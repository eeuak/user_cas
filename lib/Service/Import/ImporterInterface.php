<?php


namespace OCA\UserCAS\Service\Import;

use Psr\Log\LoggerInterface;


/**
 * Interface ImporterInterface
 * @package OCA\UserCAS\Service\Import
 *
 * @author Original contributors
 * @copyright Original contributors
 *
 * @since 1.0.0
 */
interface ImporterInterface
{

    /**
     * @param LoggerInterface $logger
     */
    public function init(LoggerInterface $logger);

    public function close();

    public function getUsers();

    /**
     * Returns all non-excluded group DNs → raw resolved names collected during getUsers().
     * @return array<string, string>
     */
    public function getResolvedGroupDns(): array;

    /**
     * @param array $userData
     */
    public function exportAsCsv(array $userData);
}