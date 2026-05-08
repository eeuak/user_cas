<?php


namespace OCA\UserCAS\Service\Import;

use OCA\UserCAS\Service\Merge\AdUserMerger;
use OCA\UserCAS\Service\Merge\MergerInterface;
use OCP\IConfig;
use Psr\Log\LoggerInterface;


/**
 * Class AdImporter
 * @package OCA\UserCAS\Service\Import
 *
 * @author Original contributors
 * @copyright Original contributors
 *
 * @since 1.0.0
 */
class AdImporter implements ImporterInterface
{
    /**
     * @var array<string, array{dn: string, name: string}>
     */
    private $excludedGroups = [];

    /**
     * @var array<string, string>  dn => raw resolved name, populated by getUsers()
     */
    private $resolvedGroupDns = [];

    /**
     * @var boolean|resource
     */
    private $ldapConnection;

    /**
     * @var MergerInterface $merger
     */
    private $merger;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * @var IConfig
     */
    private $config;

    /**
     * @var string $appName
     */
    private $appName = 'user_cas';


    /**
     * AdImporter constructor.
     * @param IConfig $config
     */
    public function __construct(IConfig $config)
    {

        $this->config = $config;
    }


    /**
     * @param LoggerInterface $logger
     *
     * @throws \Exception
     */
    public function init(LoggerInterface $logger)
    {

        $this->merger = new AdUserMerger($logger);
        $this->logger = $logger;

        $this->ldapConnect();
        $this->ldapBind();

        $this->logger->info("Init complete.");
    }

    /**
     * @throws \Exception
     */
    public function close()
    {

        $this->ldapClose();
    }

    /**
     * Get User data
     *
     * @return array User data
     */
    public function getUsers()
    {
        $mapping = $this->getImportMapping();
        $mergeAttribute = boolval($this->config->getAppValue($this->appName, 'cas_import_merge'));
        $primaryAccountDnStartswWith = $this->config->getAppValue($this->appName, 'cas_import_map_dn_filter');
        $preferEnabledAccountsOverDisabled = boolval($this->config->getAppValue($this->appName, 'cas_import_merge_enabled'));

        $pageSize = $this->config->getAppValue($this->appName, 'cas_import_ad_sync_pagesize');

        $users = [];

        $this->logger->info("Getting all users from the AD …");

        # Get all members of the sync group
        $memberPages = $this->getLdapList($this->config->getAppValue($this->appName, 'cas_import_ad_base_dn'), $this->config->getAppValue($this->appName, 'cas_import_ad_sync_filter'), $mapping['debugKeep'], $pageSize);

        foreach ($memberPages as $memberPage) {

            #var_dump($memberPage["count"]);

            for ($key = 0; $key < $memberPage["count"]; $key++) {

                $m = $memberPage[$key];
                $mappedUser = $this->mapLdapEntry($m, $mapping);

                # Fill the users array only if we have an employeeId and addUser is true
                if ($mappedUser['user']['uid'] !== '' && $mappedUser['hasGroups']) {
       # 	$this->logger->info("Merge User");

                    $this->merger->mergeUsers($users, $mappedUser['user'], $mergeAttribute, $preferEnabledAccountsOverDisabled, $primaryAccountDnStartswWith);
                }
            }
        }

        $this->logger->info("Users have been retrieved.");

        return $users;
    }

    /**
     * @return array<int, array{dn: string, name: string}>
     */
    public function getExcludedGroups()
    {
        return array_values($this->excludedGroups);
    }

    /**
     * @return array<string, string>
     */
    public function getResolvedGroupDns(): array
    {
        return $this->resolvedGroupDns;
    }

    /**
     * Debug one LDAP person and show the mapped import payload.
     *
     * @param string $identifier
     * @param string $attribute
     * @return array<int, array<string, mixed>>
     */
    public function debugUser($identifier, $attribute = '')
    {
        $mapping = $this->getImportMapping();
        $attribute = trim((string)$attribute);
        if ($attribute === '') {
            $attribute = $mapping['uidAttribute'];
        }

        $pageSize = $this->config->getAppValue($this->appName, 'cas_import_ad_sync_pagesize');
        $baseDn = $this->config->getAppValue($this->appName, 'cas_import_ad_base_dn');
        $escapedIdentifier = $this->escapeLdapFilterValue($identifier);
        $lookupFilter = sprintf('(%s=%s)', $attribute, $escapedIdentifier);
        $rawResults = [];

        $memberPages = $this->getLdapList($baseDn, $lookupFilter, $mapping['debugKeep'], $pageSize);

        foreach ($memberPages as $memberPage) {
            for ($key = 0; $key < $memberPage['count']; $key++) {
                $entry = $memberPage[$key];
                $mapped = $this->mapLdapEntry($entry, $mapping);
                $rawResults[] = [
                    'lookup_attribute' => $attribute,
                    'lookup_identifier' => (string)$identifier,
                    'lookup_filter' => $lookupFilter,
                    'matches_sync_filter' => $this->doesIdentifierMatchFilter($baseDn, $attribute, $identifier, $this->config->getAppValue($this->appName, 'cas_import_ad_sync_filter')),
                    'matches_deactivation_filter' => $this->doesIdentifierMatchFilter($baseDn, $attribute, $identifier, $this->config->getAppValue($this->appName, 'cas_import_ad_deactivate_filter')),
                    'raw_attributes' => $this->extractRawAttributes($entry, $mapping),
                    'excluded_group_dns' => $mapped['excludedGroupDns'],
                    'excluded_groups' => $mapped['excludedResolvedGroups'],
                    'resolved_groups' => $mapped['resolvedGroups'],
                    'mapped_user' => $mapped['user'],
                    'would_be_imported' => ($mapped['user']['uid'] !== '' && $mapped['hasGroups']),
                ];
            }
        }

        return $rawResults;
    }

    /**
     * Get user ids matching the optional deactivation filter.
     *
     * @return array<int, string>
     */
    public function getDeactivatedUserIds()
    {
        $deactivationFilter = trim($this->config->getAppValue($this->appName, 'cas_import_ad_deactivate_filter'));
        if ($deactivationFilter === '') {
            return [];
        }

        $uidAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_uid');
        $pageSize = $this->config->getAppValue($this->appName, 'cas_import_ad_sync_pagesize');
        $userIds = [];

        $this->logger->info("Getting users matching the AD deactivation filter …");

        $memberPages = $this->getLdapList(
            $this->config->getAppValue($this->appName, 'cas_import_ad_base_dn'),
            $deactivationFilter,
            [$uidAttribute],
            $pageSize
        );

        foreach ($memberPages as $memberPage) {
            for ($key = 0; $key < $memberPage["count"]; $key++) {
                $m = $memberPage[$key];
                $uid = isset($m[$uidAttribute][0]) ? trim((string)$m[$uidAttribute][0]) : '';
                if ($uid !== '') {
                    $userIds[$uid] = $uid;
                }
            }
        }

        $this->logger->info(sprintf('Users matching the AD deactivation filter have been retrieved: %d', count($userIds)));

        return array_values($userIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function getImportMapping()
    {
        $displayNameAttribute1 = $this->config->getAppValue($this->appName, 'cas_import_map_displayname');
        $displayNameAttribute2 = '';

        if (strpos($displayNameAttribute1, "+") !== FALSE) {
            $displayNameAttributes = explode("+", $displayNameAttribute1);
            $displayNameAttribute1 = $displayNameAttributes[0];
            $displayNameAttribute2 = $displayNameAttributes[1];
        }

        $uidAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_uid');
        $emailAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_email');
        $groupsAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_groups');
        $quotaAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_quota');
        $enableAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_enabled');
        $dnAttribute = $this->config->getAppValue($this->appName, 'cas_import_map_dn');
        $groupAttrField = $this->config->getAppValue($this->appName, 'cas_import_map_groups_description');
        $groupDnExcludeFilters = $this->parseGroupDnExcludeFilters(
            (string)$this->config->getAppValue($this->appName, 'cas_import_map_groups_exclude_dn_filter')
        );

        $debugKeep = array_values(array_filter(array_unique([
            $uidAttribute,
            $displayNameAttribute1,
            $displayNameAttribute2,
            $emailAttribute,
            $groupsAttribute,
            $quotaAttribute,
            $enableAttribute,
            $dnAttribute,
        ])));

        return [
            'uidAttribute' => $uidAttribute,
            'displayNameAttribute1' => $displayNameAttribute1,
            'displayNameAttribute2' => $displayNameAttribute2,
            'emailAttribute' => $emailAttribute,
            'groupsAttribute' => $groupsAttribute,
            'quotaAttribute' => $quotaAttribute,
            'enableAttribute' => $enableAttribute,
            'dnAttribute' => $dnAttribute,
            'groupAttrField' => $groupAttrField,
            'groupDnExcludeFilters' => $groupDnExcludeFilters,
            'andEnableAttributeBitwise' => $this->config->getAppValue($this->appName, 'cas_import_map_enabled_and_bitwise'),
            'debugKeep' => $debugKeep,
        ];
    }

    /**
     * @param array $entry
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function mapLdapEntry(array $entry, array $mapping)
    {
        $uidAttribute = $mapping['uidAttribute'];
        $displayNameAttribute1 = $mapping['displayNameAttribute1'];
        $displayNameAttribute2 = $mapping['displayNameAttribute2'];
        $emailAttribute = $mapping['emailAttribute'];
        $groupsAttribute = $mapping['groupsAttribute'];
        $quotaAttribute = $mapping['quotaAttribute'];
        $enableAttribute = $mapping['enableAttribute'];
        $dnAttribute = $mapping['dnAttribute'];
        $groupAttrField = $mapping['groupAttrField'];
        $groupDnExcludeFilters = $mapping['groupDnExcludeFilters'];
        $andEnableAttributeBitwise = $mapping['andEnableAttributeBitwise'];

        $employeeID = isset($entry[$uidAttribute][0]) ? $entry[$uidAttribute][0] : "";
        $mail = isset($entry[$emailAttribute][0]) ? $entry[$emailAttribute][0] : "";
        $dn = isset($entry[$dnAttribute]) ? $entry[$dnAttribute] : "";

        $displayName = $employeeID;
        if (isset($entry[$displayNameAttribute1][0])) {
            $displayName = $entry[$displayNameAttribute1][0];
            if (strlen($displayNameAttribute2) > 0 && isset($entry[$displayNameAttribute2][0])) {
                $displayName .= " " . $entry[$displayNameAttribute2][0];
            }
        } elseif (strlen($displayNameAttribute2) > 0 && isset($entry[$displayNameAttribute2][0])) {
            $displayName = $entry[$displayNameAttribute2][0];
        }

        $quota = isset($entry[$quotaAttribute][0]) ? intval($entry[$quotaAttribute][0]) : 0;
        $enable = 1;

        if (isset($entry[$enableAttribute][0])) {
            if (strlen($andEnableAttributeBitwise) > 0) {
                if (is_numeric($andEnableAttributeBitwise)) {
                    $andEnableAttributeBitwise = intval($andEnableAttributeBitwise);
                }
                $enable = intval((intval($entry[$enableAttribute][0]) & $andEnableAttributeBitwise) == 0);
            } else {
                $enable = intval($entry[$enableAttribute][0]);
            }
        }

        $resolvedGroups = [];
        $groupDns = [];
        $excludedGroupDns = [];
        $excludedResolvedGroups = [];
        $hasGroups = false;

        if (isset($entry[$groupsAttribute][0])) {
            for ($j = 0; $j < $entry[$groupsAttribute]['count']; $j++) {
                if (!isset($entry[$groupsAttribute][$j])) {
                    continue;
                }

                $groupCn = $entry[$groupsAttribute][$j];
                $groupDns[] = $groupCn;

                if ($this->shouldExcludeGroupDn($groupCn, $groupDnExcludeFilters)) {
                    $excludedGroupDns[] = $groupCn;
                    $excludedResolvedGroup = $this->resolveGroupFromDn($groupCn, $groupAttrField);
                    $excludedResolvedGroups[] = $excludedResolvedGroup;
                    if ($excludedResolvedGroup['name'] !== '') {
                        $this->excludedGroups[$excludedResolvedGroup['name']] = $excludedResolvedGroup;
                    }
                    continue;
                }

                $hasGroups = true;
                $resolvedGroup = $this->resolveGroupFromDn($groupCn, $groupAttrField);
                $groupName = $resolvedGroup['name'];

                if ($groupName !== '') {
                    $this->resolvedGroupDns[$groupCn] = $groupName;
                }

                if (strlen($groupName) > 0) {
                    $resolvedGroups[] = $resolvedGroup;
                }
            }
        }

        return [
            'hasGroups' => $hasGroups,
            'groupDns' => $groupDns,
            'excludedGroupDns' => $excludedGroupDns,
            'excludedResolvedGroups' => $excludedResolvedGroups,
            'resolvedGroups' => $resolvedGroups,
            'user' => [
                'uid' => $employeeID,
                'displayName' => $displayName,
                'email' => $mail,
                'quota' => $quota,
                'groups' => array_values(array_map(function (array $group) {
                    return $group['name'];
                }, $resolvedGroups)),
                'enable' => $enable,
                'dn' => $dn,
            ],
        ];
    }

    /**
     * @param array $entry
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function extractRawAttributes(array $entry, array $mapping)
    {
        $attributes = [];

        foreach ($mapping['debugKeep'] as $attribute) {
            if ($attribute === '') {
                continue;
            }

            $attributes[$attribute] = $this->getAttributeValues($entry, $attribute);
        }

        return $attributes;
    }

    /**
     * @param array $entry
     * @param string $attribute
     * @return array<int, string>
     */
    private function getAttributeValues(array $entry, $attribute)
    {
        if (!isset($entry[$attribute])) {
            return [];
        }

        $values = [];
        $count = isset($entry[$attribute]['count']) ? intval($entry[$attribute]['count']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if (isset($entry[$attribute][$i])) {
                $values[] = (string)$entry[$attribute][$i];
            }
        }

        return $values;
    }

    /**
     * @param string $baseDn
     * @param string $attribute
     * @param string $identifier
     * @param string $filter
     * @return bool
     */
    private function doesIdentifierMatchFilter($baseDn, $attribute, $identifier, $filter)
    {
        $filter = trim((string)$filter);
        if ($filter === '') {
            return false;
        }

        $combinedFilter = sprintf(
            '(&%s(%s=%s))',
            $filter,
            $attribute,
            $this->escapeLdapFilterValue($identifier)
        );

        $memberPages = $this->getLdapList($baseDn, $combinedFilter, [$attribute], 1);
        foreach ($memberPages as $memberPage) {
            if (isset($memberPage['count']) && intval($memberPage['count']) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @return string
     */
    private function escapeLdapFilterValue($value)
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return strtr($value, [
            '\\' => '\5c',
            '*' => '\2a',
            '(' => '\28',
            ')' => '\29',
            "\x00" => '\00',
        ]);
    }

    /**
     * @param string $groupDn
     * @param array<int, string> $excludeFilters
     * @return bool
     */
    private function shouldExcludeGroupDn($groupDn, array $excludeFilters)
    {
        foreach ($excludeFilters as $excludeFilter) {
            if ($excludeFilter !== '' && stripos($groupDn, $excludeFilter) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $groupDn
     * @param string $groupAttrField
     * @return array{dn: string, name: string}
     */
    private function resolveGroupFromDn($groupDn, $groupAttrField)
    {
        $groupAttr = $this->getLdapAttributes($groupDn, [$groupAttrField]);
        $groupName = '';

        if (isset($groupAttr[$groupAttrField][0])) {
            $groupName = $groupAttr[$groupAttrField][0];
        } else {
            $groupCnArray = explode(",", $groupDn);
            $groupName = substr($groupCnArray[0], 3, strlen($groupCnArray[0]));
        }

        return [
            'dn' => $groupDn,
            'name' => $groupName,
        ];
    }

    /**
     * Parse excluded group DN filters from admin config.
     * Supports one entry per line or ';' as separator.
     *
     * @param string $rawValue
     * @return array<int, string>
     */
    private function parseGroupDnExcludeFilters($rawValue)
    {
        $rawValue = trim((string)$rawValue);
        if ($rawValue === '') {
            return [];
        }

        if (strpos($rawValue, "\n") !== false || strpos($rawValue, "\r") !== false || strpos($rawValue, ';') !== false) {
            return array_values(array_filter(array_map('trim', preg_split("/[\r\n;]+/", $rawValue))));
        }

        return [$rawValue];
    }


    /**
     * List ldap entries in the base dn
     *
     * @param string $object_dn
     * @param $filter
     * @param array $keepAtributes
     * @param $pageSize
     * @return array
     */
    protected function getLdapList($object_dn, $filter, $keepAtributes, $pageSize)
    {

        $cookie = '';
        $members = [];
        $errcode = null;
        $matcheddn = null;
        $errmsg = null;
        $referrals = null;
        $controls = null;

        do {

            // Query Group members
//            ldap_control_paged_result($this->ldapConnection, $pageSize, false, $cookie);

                $results = ldap_search($this->ldapConnection, $object_dn, $filter, $keepAtributes, 0, -1, -1, LDAP_DEREF_NEVER, [['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => $pageSize, 'cookie' => $cookie]]]/*, array("member;range=$range_start-$range_end")*/) or die('Error searching LDAP: ' . ldap_error($this->ldapConnection));
                ldap_parse_result($this->ldapConnection, $results, $errcode , $matcheddn , $errmsg , $referrals, $controls);
            $members[] = ldap_get_entries($this->ldapConnection, $results);
        if (isset($controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])) {
                // You need to pass the cookie from the last call to the next one
                $cookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'];
            } else {
                $cookie = '';
            }
//            ldap_control_paged_result_response($this->ldapConnection, $results, $cookie);

        } while ($cookie !== null && $cookie != '');

        // Return sorted member list
        sort($members);

        return $members;
    }


    /**
     * @param string $user_dn
     * @param bool $keep
     * @return array Attribute list
     */
    protected function getLdapAttributes($user_dn, $keep = false)
    {

        if (!isset($this->ldapConnection)) die('Error, no LDAP connection established');
        if (empty($user_dn)) die('Error, no LDAP user specified');

        // Disable pagination setting, not needed for individual attribute queries
        //ldap_control_paged_result($this->ldapConnection, 1);

        // Query user attributes
        $results = (($keep) ? ldap_search($this->ldapConnection, $user_dn, 'cn=*', $keep) : ldap_search($this->ldapConnection, $user_dn, 'cn=*'))
        or die('Error searching LDAP: ' . ldap_error($this->ldapConnection));

        $attributes = ldap_get_entries($this->ldapConnection, $results);

        $this->logger->debug("AD attributes successfully retrieved.");

        // Return attributes list
        if (isset($attributes[0])) return $attributes[0];
        else return array();
    }


    /**
     * Connect ldap
     *
     * @return bool|resource
     * @throws \Exception
     */
    protected function ldapConnect()
    {
        try {

            $host = $this->config->getAppValue($this->appName, 'cas_import_ad_host');

            $this->ldapConnection = ldap_connect($this->config->getAppValue($this->appName, 'cas_import_ad_protocol') . $host . ":" . $this->config->getAppValue($this->appName, 'cas_import_ad_port')) or die("Could not connect to " . $host);

            ldap_set_option($this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->ldapConnection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($this->ldapConnection, LDAP_OPT_NETWORK_TIMEOUT, 10);

            $this->logger->info("AD connected successfully.");

            return $this->ldapConnection;
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Bind ldap
     *
     * @throws \Exception
     */
    protected function ldapBind()
    {

        try {

            if ($this->ldapConnection) {

                $ldapIsBound = ldap_bind($this->ldapConnection, $this->config->getAppValue($this->appName, 'cas_import_ad_user') . "@" . $this->config->getAppValue($this->appName, 'cas_import_ad_domain'), $this->config->getAppValue($this->appName, 'cas_import_ad_password'));

                if (!$ldapIsBound) {

                    throw new \Exception("LDAP bind failed. Error: " . ldap_error($this->ldapConnection));
                } else {

                    $this->logger->info("AD bound successfully.");
                }
            }
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Unbind ldap
     *
     * @throws \Exception
     */
    protected function ldapUnbind()
    {

        try {

            ldap_unbind($this->ldapConnection);

            $this->logger->info("AD unbound successfully.");
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * Close ldap connection
     *
     * @throws \Exception
     */
    protected function ldapClose()
    {
        try {

            ldap_close($this->ldapConnection);

            $this->logger->info("AD connection closed successfully.");
        } catch (\Exception $e) {

            throw $e;
        }
    }

    /**
     * @param array $exportData
     */
    public function exportAsCsv(array $exportData)
    {

        $this->logger->info("Exporting users to .csv …");

        $fp = fopen('accounts.csv', 'wa+');

        fputcsv($fp, ["UID", "displayName", "email", "quota", "groups", "enabled"]);

        foreach ($exportData as $fields) {

            for ($i = 0; $i < count($fields); $i++) {

                if (is_array($fields[$i])) {

                    $fields[$i] = $this->multiImplode($fields[$i], " ");
                }
            }

            fputcsv($fp, $fields);
        }

        fclose($fp);

        $this->logger->info("CSV export finished.");
    }

    /**
     * @param array $exportData
     */
    public function exportAsText(array $exportData)
    {

        $this->logger->info("Exporting users to .txt …");

        file_put_contents('accounts.txt', serialize($exportData));

        $this->logger->info("TXT export finished.");
    }

    /**
     * @param array $array
     * @param string $glue
     * @return bool|string
     */
    private function multiImplode($array, $glue)
    {
        $ret = '';

        foreach ($array as $item) {
            if (is_array($item)) {
                $ret .= $this->multiImplode($item, $glue) . $glue;
            } else {
                $ret .= $item . $glue;
            }
        }

        $ret = substr($ret, 0, 0 - strlen($glue));

        return $ret;
    }
}
