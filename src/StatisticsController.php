<?php

namespace PhpWebsite;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

class StatisticsController
{

    private PhpWebsite $smart;
    private $table;

    public function __construct($smart, $table)
    {
        $this->smart = $smart;
        $this->table = $table;
    }

    public function process($page, $language, $navigator, $request)
    {
        $prefix = $this->smart->getSubDir();
        if (substr($page, 0, strlen($prefix)) == $prefix) {
            $page = substr($page, strlen($prefix));
        }
        if (substr($page, 0, 3) == $language . '/') {
            $page = substr($page, 3);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        if ($ip == '127.0.0.1') return;
        $country = 'XX';
        if (isset($_SESSION)) {
            if (isset($_SESSION['country']) && isset($_SESSION['ip']) && $_SESSION['ip'] == $ip) {
                $country = $_SESSION['country'];
            } else {
                $country = $this->lookupCountry($ip);
                $_SESSION['country'] = $country;
                $_SESSION['ip'] = $ip;
            }
        } else {
            $country = $this->lookupCountry($ip);
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $CrawlerDetect = new CrawlerDetect();
        $bot = $CrawlerDetect->isCrawler();
        $utm_source = isset($_GET['utm_source']) ? $_GET['utm_source'] : '';
        $utm_medium = isset($_GET['utm_medium']) ? $_GET['utm_medium'] : '';
        $utm_campaign = isset($_GET['utm_campaign']) ? $_GET['utm_campaign'] : '';
        $utm_content = isset($_GET['utm_content']) ? $_GET['utm_content'] : '';
        $unique_visit = strpos($referer, $this->smart->getDomain()) === false ? true : false;
        $timestamp = date('Y-m-d H:i');
        $useragent = $bot ? substr($_SERVER['HTTP_USER_AGENT'], 0, 1023) : '';

        // VALIDATION
        $country = $country == null ? 'XX' : substr($country . 'XX', 0, 2);

        // STORE in DATABASE
        $database = Database::getInstance();
        $stmt = $database->prepare('INSERT INTO ' . $this->table .
            ' (timestamp, page, unique_visit, language, country, referer, utm_source, utm_medium, utm_campaign, utm_content, device, os, os_version, browser, browser_version, bot, request, useragent) ' .
            'VALUES (:timestamp, :page, :unique_visit, :language, :country, :referer, :utm_source, :utm_medium, :utm_campaign, :utm_content, :device, :os, :os_version, :browser, :browser_version, :bot, :request, :useragent)');
        $stmt->bindParam(':timestamp', $timestamp, \PDO::PARAM_STR);
        $stmt->bindParam(':page', $page, \PDO::PARAM_STR);
        $stmt->bindParam(':unique_visit', $unique_visit, \PDO::PARAM_INT);
        $stmt->bindParam(':language', $language, \PDO::PARAM_STR);
        $stmt->bindParam(':country', $country, \PDO::PARAM_STR);
        $stmt->bindParam(':referer', $referer, \PDO::PARAM_STR);
        $stmt->bindParam(':utm_source', $utm_source, \PDO::PARAM_STR);
        $stmt->bindParam(':utm_medium', $utm_medium, \PDO::PARAM_STR);
        $stmt->bindParam(':utm_campaign', $utm_campaign, \PDO::PARAM_STR);
        $stmt->bindParam(':utm_content', $utm_content, \PDO::PARAM_STR);
        $stmt->bindParam(':device', $navigator['device_type'], \PDO::PARAM_STR);
        $stmt->bindParam(':os', $navigator['os_name'], \PDO::PARAM_STR);
        $stmt->bindParam(':os_version', $navigator['os_version'], \PDO::PARAM_STR);
        $stmt->bindParam(':browser', $navigator['browser_name'], \PDO::PARAM_STR);
        $stmt->bindParam(':browser_version', $navigator['browser_version'], \PDO::PARAM_STR);
        $stmt->bindParam(':bot', $bot, \PDO::PARAM_INT);
        $stmt->bindParam(':request', $request, \PDO::PARAM_STR);
        $stmt->bindParam(':useragent', $useragent, \PDO::PARAM_STR);
        $result = $stmt->execute();
    }

    private function lookupCountry($ip)
    {
        @$lookup_raw = file_get_contents('https://www.iplocate.io/api/lookup/' . $ip);
        if (isset($lookup_raw)) {
            $lookup = json_decode($lookup_raw);
            return $lookup->country_code;
        }
        return 'XX';
    }

    public function view($params)
    {
        return array(
            'type' => 'html',
            'stats' => 'stats',
            'http_response_code' => 200,
            'yaml+md' =>
            '---
title: Stats
---

Under construction

',
        );
    }
}
