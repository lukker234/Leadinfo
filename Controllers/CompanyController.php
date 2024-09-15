<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

class CompanyController {
    protected $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // Helper function to check if a country filter is defined
    private function countryIsDefined(Request $request): bool {
        $params = $request->getQueryParams();
        return isset($params['filter']['country']);
    }

    // Determine the correct country-specific table
    private function getTable(Request $request): string {
        $params = $request->getQueryParams();
        return $this->countryIsDefined($request) 
            ? strtolower('company_' . $params['filter']['country']) 
            : 'company_nl'; // Default to 'company_nl' if no country filter
    }

    // Helper function to return JSON response
    private function returnJSON(array $result, Response $response): Response {
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Main function to get companies (either filtered or all)
    public function getCompanies(Request $request, Response $response): Response {
        $params = $request->getQueryParams();

        // If no filter params are provided, return all companies from all tables
        if (empty($params['filter']) && empty($params['sort'])) {
            return $this->getAllCompanies($response);
        }

        // Otherwise, apply filtering and sorting using buildQuery
        [$query, $queryParams] = $this->buildQuery($request);

        // Prepare and execute the query
        $stmt = $this->db->prepare($query);
        $stmt->execute($queryParams);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->returnJSON($result, $response);
    }

    // Function to fetch all companies from all country-specific tables
    public function getAllCompanies(Response $response): Response {
        // Query to get all country-specific tables (those starting with 'company_')
        $tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'db' AND table_name LIKE 'company_%'";
        $tablesStmt = $this->db->query($tablesQuery);
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $queries = [];
        foreach ($tables as $table) {
            // Dynamically build queries for each country-specific table
            $queries[] = "SELECT company.id, '$table' AS table_name, $table.name, $table.city, $table.country
                          FROM company 
                          INNER JOIN $table ON company.data_table = '$table' 
                          AND company.data_unique_id = $table.unique_id";
        }

        if (empty($queries)) {
            throw new Exception('No relevant tables found.');
        }

        // Combine all queries with UNION
        $query = implode(" UNION ALL ", $queries);
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->returnJSON($result, $response);
    }

    // Function to build dynamic queries for filtering and sorting
    private function buildQuery(Request $request): array {
        $params = $request->getQueryParams();
        $filter = isset($params['filter']) ? $params['filter'] : [];
        $sort = isset($params['sort']) ? $params['sort'] : [];

        $whereClauses = [];
        $queryParams = [];

        // Determine the correct table (e.g., company_nl, company_us)
        $table = $this->getTable($request);

        // Base query: Select from 'company' table and join the country-specific table
        $baseQuery = "SELECT company.id, $table.name, $table.city, $table.country 
                      FROM company 
                      INNER JOIN $table ON company.data_table = '$table' AND company.data_unique_id = $table.unique_id";

        // Filter by name (if provided)
        if (isset($filter['name'])) {
            $whereClauses[] = "$table.name LIKE ?";
            $queryParams[] = '%' . $filter['name'] . '%';
        }

        // Add WHERE clauses to the query
        if (!empty($whereClauses)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        // Sort by city (if sorting is provided)
        if (!empty($sort)) {
            $sortClauses = [];
            foreach ($sort as $column => $direction) {
                $sortClauses[] = "$table.$column " . strtoupper($direction);
            }
            if (!empty($sortClauses)) {
                $baseQuery .= ' ORDER BY ' . implode(', ', $sortClauses);
            }
        }

        // Return both the query string and the parameters
        return [$baseQuery, $queryParams];
    }
}
