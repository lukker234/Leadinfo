<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;
use App\Helpers\QueryHelpers;

class CompanyController {
    protected $db;
    private $tableNamesCache = null;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function returnJSON(array $result, Response $response): Response {
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Fetch and cache table names from the database.
    private function getTableNames(): array {
        if ($this->tableNamesCache === null) {
            $tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'db' AND table_name LIKE 'company_%'";
            $tablesStmt = $this->db->query($tablesQuery);
            $this->tableNamesCache = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $this->tableNamesCache;
    }

    // Get the appropriate table name based on the country filter.
    private function getTable(Request $request): string {
        $filter = QueryHelpers::extractFilter($request);
        return isset($filter['country']) 
            ? strtolower('company_' . $filter['country']) 
            : 'company_nl'; // Default to 'company_nl' if no country filter
    }

    // Retrieve companies based on filters and sorting.
    public function getCompanies(Request $request, Response $response): Response {
        $filter = QueryHelpers::extractFilter($request);
        $sort = QueryHelpers::extractSort($request);

        if (isset($filter['country'])) {
            [$query, $queryParams] = $this->buildQuery($request, $filter, $sort);
            $stmt = $this->db->prepare($query);
            $stmt->execute($queryParams);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $result = $this->getAllCompanies($request, $filter, $sort);
        }

        return $this->returnJSON($result, $response);
    }

    // Fetch all companies from all country-specific tables.
    public function getAllCompanies(Request $request, array $filter = [], array $sort = []): array {
        $tables = $this->getTableNames();
        $queries = [];
        $queryParams = [];

        foreach ($tables as $table) {
            $query = "SELECT company.id, '$table' AS table_name, $table.*
                      FROM company 
                      INNER JOIN $table ON company.data_table = '$table' 
                      AND company.data_unique_id = $table.unique_id";

            [$filterClauses, $filterParams] = QueryHelpers::applyFilters($table, $filter);
            $queryParams = array_merge($queryParams, $filterParams);

            if (!empty($filterClauses)) {
                $query .= ' WHERE ' . implode(' AND ', $filterClauses);
            }

            $queries[] = $query;
        }

        if (empty($queries)) {
            throw new Exception('No relevant tables found.');
        }

        $combinedQuery = "SELECT * FROM (" . implode(" UNION ALL ", $queries) . ") AS combined";
        $combinedQuery .= QueryHelpers::applySort('combined', $sort);

        $stmt = $this->db->prepare($combinedQuery);
        $stmt->execute($queryParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Build the query with filtering and sorting based on the request.
    private function buildQuery(Request $request, array $filter, array $sort): array {
        $whereClauses = [];
        $queryParams = [];

        $table = $this->getTable($request);
        $baseQuery = "SELECT company.id, $table.*
                      FROM company 
                      INNER JOIN $table ON company.data_table = '$table' AND company.data_unique_id = $table.unique_id";

        [$filterClauses, $filterParams] = QueryHelpers::applyFilters($table, $filter);
        $queryParams = array_merge($queryParams, $filterParams);

        if (!empty($filterClauses)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $filterClauses);
        }

        $baseQuery .= QueryHelpers::applySort($table, $sort);
        return [$baseQuery, $queryParams];
    }
}
