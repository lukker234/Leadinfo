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
    
            // Filter by each field (if provided)
            $filterClauses = [];
            foreach ($filter as $field => $value) {
                if ($field === 'name') {
                    // Special case for name, use LIKE
                    $filterClauses[] = "$table.$field LIKE ?";
                    $queryParams[] = "%$value%";
                } else {
                    // General case for other fields
                    $filterClauses[] = "$table.$field = ?";
                    $queryParams[] = $value;
                }
            }
    
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
    
        // Apply sorting if provided
        if (!empty($sort)) {
            $sortClauses = [];
            foreach ($sort as $column => $direction) {
                // Sort by columns present in the results
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

        // Filter by each field (if provided)
        foreach ($filter as $field => $value) {
            if ($field === 'name') {
                // Special case for name, use LIKE
                $whereClauses[] = "$table.$field LIKE ?";
                $queryParams[] = "%$value%";
            } else {
                // General case for other fields
                $whereClauses[] = "$table.$field = ?";
                $queryParams[] = $value;
            }
        }

        // Add WHERE clauses to the query
        if (!empty($whereClauses)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        // Sort by columns (if sorting is provided)
        if (!empty($sort)) {
            $sortClauses = [];
            foreach ($sort as $column => $direction) {
                // Make sure to sort by fields present in the table
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
