<?php

namespace PhpWebsite;

use Spyc;

class BlogController
{

    private string $blogSlug = '';
    private PhpWebsite $smart;
    private string $template = '---
    title: Blog
    ---
    
    {% for blogpost in blogs %}
    * <a href="{{ blogpost.link }}">{{ blogpost.title }}</a>
    {% endfor %}
    ';

    public function __construct($blogSlug, $smart, $template = '')
    {
        $this->blogSlug = $blogSlug;
        $this->smart = $smart;
        if ($template != '') $this->template = $template;
    }

    public function all($params)
    {
        $blogs = array();
        $contentDir = $this->smart->getContentDir();
        $content = $this->smart->getContent();

        if (isset($content['language'])) {
            $lang = '.' . $content['language'];
        } else {
            $lang = '';
        }
        foreach (glob($contentDir . $this->blogSlug . '*' . $lang . '.md') as $file) {
            $everything = substr(file_get_contents($file), 3);
            $yaml = strstr($everything, '---', true);
            $blog = Spyc::YAMLLoad($yaml);
            $blogs[] = array_merge($blog, array(
                'link' => str_replace($contentDir, '', substr($file, 0, -6)) . '.html',
            ));
        }

        return array(
            'type' => 'html',
            'stats' => 'blog',
            'blogs' => $blogs,
            'http_response_code' => 200,
            'yaml+md' => $this->template,
        );
    }
}
