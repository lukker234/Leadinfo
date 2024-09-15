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

    private function countryIsDefined(Request $request): bool {
        $params = $request->getQueryParams();
        return isset($params['filter']['country']);
    }

    private function getTable(Request $request): string {
        $params = $request->getQueryParams();
        return $this->countryIsDefined($request) 
            ? strtolower('company_' . $params['filter']['country']) 
            : 'company_nl';
    }

    private function returnJSON(array $result, Response $response): Response {
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCompanies(Request $request, Response $response): Response {
        if ($this->countryIsDefined($request)) {
            return $this->getCountryCompanies($request, $response);
        }

        return $this->getAllCompanies($response);
    }

    public function getAllCompanies(Response $response): Response {
        $tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'db'";
        
        $tablesStmt = $this->db->query($tablesQuery);
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $queries = [];
        foreach ($tables as $table) {
            if (preg_match('/^company_/', $table)) {
                $queries[] = "SELECT company.id, '$table' AS table_name, $table.* FROM company 
                    INNER JOIN $table ON company.data_table = '$table' 
                    AND company.data_unique_id = $table.unique_id";
            }
        }

        if (empty($queries)) {
            throw new Exception('No relevant tables found.');
        }

        $query = implode(" UNION ALL ", $queries);
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->returnJSON($result, $response);
    }

    public function getCountryCompanies(Request $request, Response $response): Response {
        $table = $this->getTable($request);

        $tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'db' AND table_name = ?";
        $stmt = $this->db->prepare($tablesQuery);
        $stmt->execute([$table]);
        if (!$stmt->fetch()) {
            throw new Exception('Table not found.');
        }

        $query = "SELECT company.id, $table.* FROM company 
            INNER JOIN $table ON company.data_table = '$table' 
            AND company.data_unique_id = $table.unique_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->returnJSON($result, $response);
    }
}
