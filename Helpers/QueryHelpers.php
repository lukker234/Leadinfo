<?php

namespace App\Helpers;

use Psr\Http\Message\ServerRequestInterface as Request;

class QueryHelpers {

    // Extract filter parameters from the request.
    public static function extractFilter(Request $request): array {
        $params = $request->getQueryParams();
        return isset($params['filter']) ? $params['filter'] : [];
    }

    // Extract sort parameters from the request.
    public static function extractSort(Request $request): array {
        $params = $request->getQueryParams();
        return isset($params['sort']) ? $params['sort'] : [];
    }

    // Create filter clauses and parameters for the query.
    public static function applyFilters(string $table, array $filter): array {
        $filterClauses = [];
        $queryParams = [];

        foreach ($filter as $field => $value) {
            if ($field === 'name' || $field === 'city') {
                $filterClauses[] = "$table.$field LIKE ?";
                $queryParams[] = "%$value%";
            } else {
                $filterClauses[] = "$table.$field = ?";
                $queryParams[] = $value;
            }
        }

        return [$filterClauses, $queryParams];
    }

    // Create sorting clauses for the query.
    public static function applySort(string $table, array $sort): string {
        $sortClauses = [];

        foreach ($sort as $column => $direction) {
            $sortClauses[] = "$table.$column " . strtoupper($direction);
        }

        return !empty($sortClauses) ? ' ORDER BY ' . implode(', ', $sortClauses) : '';
    }
}
