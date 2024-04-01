<?php

namespace PhpWebsite;

class StaticContentController
{

    private array $languages;

    private string $contentDir = '';

    public function __construct($contentDir, $smart)
    {
        $this->contentDir = $contentDir;
        $this->languages = $smart->getLanguages();
    }

    public function view($page)
    {
        // PREPARE the CODE
        $page->code = strtolower($page->code);
        if (substr($page->code, -1, 1) == '/') {
            $page->code = substr($page->code, 0, -1);
        }
        if (substr($page->code, -5, 5) == '.html') {
            $page->code = substr($page->code, 0, -5);
        }

        // FIND the MARKDOWN FILE and GET CONTENTS
        $file = $this->contentDir . $page->code . '.' . $page->language . '.md';
        // check for the file itself
        if (\file_exists($file)) {
            $content = \file_get_contents($file);
            return array(
                'type' => 'html',
                'stats' => $page->code,
                'yaml+md' => $content,
                'file' => $file,
                'modified_time' => date("c", filemtime($file)),
                'code' => $page->code,
            );
        } else {
            // check for the file in other languages
            $languages = $this->languages;
            foreach ($languages as $l) {
                $file = $this->contentDir . $page->code . '.' . $l . '.md';
                if (\file_exists($file)) {
                    $content = \file_get_contents($file);
                    return array(
                        'type' => 'html',
                        'stats' => $page->code,
                        'yaml+md' => $content,
                        'file' => $file,
                        'modified_time' => date("c", filemtime($file)),
                        'code' => $page->code,
                    );
                }
            }
            // check for 404 - start with the requested language
            array_unshift($languages, $page->language);
            foreach ($languages as $l) {
                $f404 = $this->contentDir . '404.' . $l . '.md';
                if (\file_exists($f404)) {
                    $content = \file_get_contents($f404);
                    return array(
                        'type' => 'html',
                        'stats' => '404',
                        'http_response_code' => 404,
                        'code' => '404',
                        'file' => $f404,
                        'modified_time' => date("c", filemtime($f404)),
                        'yaml+md' => $content,
                    );
                }
            }
        }
        // if nothing worked, send a default 404
        return array(
            'type' => 'html',
            'stats' => '404',
            'code' => '404',
            'http_response_code' => 404,
            'yaml+md' =>
            '---
title: Page not found
---

The page you are looking for cannot be found.
',
        );
    }
}
