<?php

declare(strict_types=1);

namespace App\Services;

class SchemeWorkspaceService
{
    public static function pmSuryaGharMetadata(string $tenantId): array
    {
        $schemeKey = 'pm_surya_ghar';
        $scheme = RegistryService::getSchemeByKey($schemeKey) ?? [];
        $schemeMeta = TenantStorageService::getTenantSchemeMeta($tenantId, $schemeKey);
        $settings = TenantStorageService::getTenantSchemeSettings($tenantId, $schemeKey);
        $workflowRuntime = RegistryService::getConfigData(TenantStorageService::getTenantSchemePath($tenantId, $schemeKey) . '/workflow_runtime.json');

        $configStatus = [
            'templates' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'templates')),
            'message_templates' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'message_templates')),
            'explainer_content' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'explainer_content')),
            'rate_chart' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'rate_chart')),
            'calculations' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'calculations')),
            'content_blocks' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'content_blocks')),
            'document_rules' => self::hasConfigData(TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'document_rules')),
            'settings_loaded' => $settings !== [],
            'workflow_loaded' => ($workflowRuntime['data']['stages'] ?? []) !== [],
        ];

        $records = [
            'leads' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'leads'),
            'customers' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers'),
            'quotations' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations'),
            'solar_finance' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'solar_finance'),
            'agreements' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements'),
            'receipts' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'receipts'),
            'invoices' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'invoices'),
            'complaints' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'complaints'),
        ];

        $dashboardSummary = [
            'leads_count' => count($records['leads']),
            'follow_ups_due_count' => self::countFollowUpsDue($records['leads']),
            'customers_count' => count($records['customers']),
            'draft_quotations_count' => self::countByStatus($records['quotations'], ['draft', 'in_progress']),
            'accepted_quotations_count' => self::countByStatus($records['quotations'], ['accepted', 'approved']),
            'pending_agreements_count' => self::countByStatus($records['agreements'], ['draft', 'pending', 'awaiting_sign']),
            'receipts_count' => count($records['receipts']),
            'invoices_count' => count($records['invoices']),
            'open_complaints_count' => self::countByStatus($records['complaints'], ['open', 'new', 'pending']),
            'recent_solar_finance_reports_count' => self::countByStatus($records['solar_finance'], ['completed', 'quoted']),
        ];

        $workflow = $workflowRuntime['data'] ?? [];
        $stages = (array) ($workflow['stages'] ?? []);
        usort($stages, fn(array $a, array $b): int => (int) ($a['order'] ?? 999) <=> (int) ($b['order'] ?? 999));

        return [
            'scheme_key' => $schemeKey,
            'scheme_slug' => 'pm-surya-ghar',
            'scheme' => $scheme,
            'scheme_meta' => $schemeMeta,
            'settings' => $settings,
            'workflow' => $workflow,
            'stages' => $stages,
            'module_groups' => (array) ($workflow['module_groups'] ?? []),
            'module_metadata' => (array) ($workflow['module_metadata'] ?? []),
            'quick_actions' => (array) ($workflow['dashboard_quick_actions'] ?? []),
            'route_metadata' => (array) ($workflow['route_metadata'] ?? []),
            'dashboard_summary' => $dashboardSummary,
            'config_status' => $configStatus,
        ];
    }

    public static function buildSchemeNavigation(array $vendor, array $workspace): array
    {
        $nav = [];
        $groups = (array) ($workspace['module_groups'] ?? []);
        $routeMetadata = (array) ($workspace['route_metadata'] ?? []);

        foreach ($groups as $group) {
            $items = [];
            foreach ((array) ($group['module_keys'] ?? []) as $moduleKey) {
                if (!isset($routeMetadata[$moduleKey])) {
                    continue;
                }
                if (!AccessService::hasModuleAccess($vendor, (string) $moduleKey, (string) $workspace['scheme_key'])) {
                    continue;
                }
                $items[] = $routeMetadata[$moduleKey];
            }

            if ($items === []) {
                continue;
            }

            usort($items, fn(array $a, array $b): int => (int) ($a['nav_order'] ?? 999) <=> (int) ($b['nav_order'] ?? 999));
            $nav[] = [
                'group_key' => (string) ($group['group_key'] ?? ''),
                'group_label' => (string) ($group['group_label'] ?? 'Modules'),
                'items' => $items,
            ];
        }

        return $nav;
    }

    public static function getRouteContext(array $workspace, string $path): ?array
    {
        foreach ((array) ($workspace['route_metadata'] ?? []) as $route) {
            if (($route['path'] ?? '') === $path) {
                return $route;
            }
        }

        return null;
    }

    private static function hasConfigData(array $payload): bool
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $data !== [];
        }

        return !empty($data);
    }


    private static function countFollowUpsDue(array $leads): int
    {
        $today = date('Y-m-d');
        $count = 0;
        foreach ($leads as $lead) {
            $due = substr((string) ($lead['follow_up_date'] ?? ''), 0, 10);
            if ($due !== '' && $due <= $today && empty($lead['archived_flag'])) {
                $count++;
            }
        }

        return $count;
    }

    private static function countByStatus(array $items, array $statuses): int
    {
        $normalized = array_map('strtolower', $statuses);
        $count = 0;
        foreach ($items as $item) {
            $status = strtolower((string) ($item['status'] ?? ''));
            if ($status !== '' && in_array($status, $normalized, true)) {
                $count++;
            }
        }

        if ($count === 0 && $items !== [] && in_array('draft', $normalized, true)) {
            return count($items);
        }

        return $count;
    }
}
