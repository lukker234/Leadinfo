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

    // Helper function to extract filter parameters
    private function extractFilter(Request $request): array {
        $params = $request->getQueryParams();
        return isset($params['filter']) ? $params['filter'] : [];
    }

    // Helper function to extract sort parameters
    private function extractSort(Request $request): array {
        $params = $request->getQueryParams();
        return isset($params['sort']) ? $params['sort'] : [];
    }

    // Determine the correct country-specific table
    private function getTable(Request $request): string {
        $filter = $this->extractFilter($request);
        return isset($filter['country']) 
            ? strtolower('company_' . $filter['country']) 
            : 'company_nl'; // Default to 'company_nl' if no country filter
    }

    // Apply filters to the query
    private function applyFilters(string $table, array $filter): array {
        $filterClauses = [];
        $queryParams = [];

        foreach ($filter as $field => $value) {
            if ($field === 'name') {
                $filterClauses[] = "$table.$field LIKE ?";
                $queryParams[] = "%$value%";
            } else {
                $filterClauses[] = "$table.$field = ?";
                $queryParams[] = $value;
            }
        }

        return [$filterClauses, $queryParams];
    }

    // Apply sorting to the query
    private function applySort(string $table, array $sort): string {
        $sortClauses = [];
        
        foreach ($sort as $column => $direction) {
            $sortClauses[] = "$table.$column " . strtoupper($direction);
        }

        return !empty($sortClauses) ? ' ORDER BY ' . implode(', ', $sortClauses) : '';
    }

    // Helper function to return JSON response
    private function returnJSON(array $result, Response $response): Response {
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Main function to get companies (either filtered or all)
    public function getCompanies(Request $request, Response $response): Response {
        $filter = $this->extractFilter($request);
        $sort = $this->extractSort($request);

        // Check if there's a country filter
        if (isset($filter['country'])) {
            // Apply filtering and sorting using buildQuery
            [$query, $queryParams] = $this->buildQuery($request, $filter, $sort);

            // Prepare and execute the query
            $stmt = $this->db->prepare($query);
            $stmt->execute($queryParams);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If no country filter, get all companies from all tables
            $result = $this->getAllCompanies($request, $filter, $sort);
        }

        return $this->returnJSON($result, $response);
    }

    // Function to fetch all companies from all country-specific tables
    public function getAllCompanies(Request $request, array $filter = [], array $sort = []): array {
        // Query to get all country-specific tables (those starting with 'company_%')
        $tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'db' AND table_name LIKE 'company_%'";
        $tablesStmt = $this->db->query($tablesQuery);
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $queries = [];
        $queryParams = []; // Initialize queryParams here
        
        foreach ($tables as $table) {
            // Base query for each table
            $query = "SELECT company.id, '$table' AS table_name, $table.*
                    FROM company 
                    INNER JOIN $table ON company.data_table = '$table' 
                    AND company.data_unique_id = $table.unique_id";
        
            // Apply filters
            [$filterClauses, $filterParams] = $this->applyFilters($table, $filter);
            $queryParams = array_merge($queryParams, $filterParams);
        
            // Add WHERE clauses to each individual query if filters are provided
            if (!empty($filterClauses)) {
                $query .= ' WHERE ' . implode(' AND ', $filterClauses);
            }
        
            // Add the query to the list
            $queries[] = $query;
        }
        
        if (empty($queries)) {
            throw new Exception('No relevant tables found.');
        }
        
        // Combine all queries with UNION ALL
        $combinedQuery = implode(" UNION ALL ", $queries);
        
        // Apply sorting to columns that are guaranteed to be present in the results
        if (!empty($sort)) {
            $sortClauses = [];
            foreach ($sort as $column => $direction) {
                // Check if the column is present in the tables
                $sortClauses[] = "$column " . strtoupper($direction);
            }
            if (!empty($sortClauses)) {
                $combinedQuery .= ' ORDER BY ' . implode(', ', $sortClauses);
            }
        }
        
        // Prepare and execute the final combined query
        $stmt = $this->db->prepare($combinedQuery);
        $stmt->execute($queryParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    
    // Function to build dynamic queries for filtering and sorting
    private function buildQuery(Request $request, array $filter, array $sort): array {
        $whereClauses = [];
        $queryParams = [];

        // Determine the correct table (e.g., company_nl, company_us)
        $table = $this->getTable($request);

        // Base query: Select from 'company' table and join the country-specific table
        $baseQuery = "SELECT company.id, $table.*
                      FROM company 
                      INNER JOIN $table ON company.data_table = '$table' AND company.data_unique_id = $table.unique_id";

        // Apply filters
        [$filterClauses, $filterParams] = $this->applyFilters($table, $filter);
        $queryParams = array_merge($queryParams, $filterParams);

        // Add WHERE clauses to the query
        if (!empty($filterClauses)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $filterClauses);
        }

        // Apply sorting
        $baseQuery .= $this->applySort($table, $sort);

        // Return both the query string and the parameters
        return [$baseQuery, $queryParams];
    }
}
