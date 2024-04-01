<?php

namespace PhpWebsite;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Liquid\Template;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use SmartWebsite\SmartMailer;
use foroco\BrowserDetection;
use Spyc;

class PhpWebsite
{
    private array $languages = array('en');
    private string $default_language = 'en';
    private array $urls = array();
    private array $menu = array();
    private string $subdir = '';
    private string $domain = '';
    private string $ssl = 's';
    private string $staticContentDir = './content/';
    private string $templateDir = './t/';
    private array $content = array();
    private array $sectionSeparators;
    private bool $usingSessions;
    private ?StatisticsController $stats = null;
    private SmartMailer $mailer;

    public function __construct($siteName, $usingSessionns = false)
    {
        $this->content['language'] = $this->default_language;
        $this->content['template'] = 'default';
        $this->content['site'] = array(
            'name' => $siteName,
            'menu' => array(),
        );
        $browser = new BrowserDetection();
        $useragent = $_SERVER['HTTP_USER_AGENT'];
        $this->content['navigator'] = $browser->getAll($useragent);
        $this->content['navigator']['browser_version'] = 1 * preg_replace(array('/\..*/', '/[^0-9]/'), '', $this->content['navigator']['browser_version']);
        if ($usingSessionns) {
            session_start(array(
                'cookie_httponly' => 1,
                'use_only_cookies' => 1,
            ));
            $this->processCookies();
        }
        $this->usingSessions = $usingSessionns;
        $this->setSectionSeparators(array('/brol/'), array('brol'));
    }

    public function setStatisticsController($stats)
    {
        $this->stats = $stats;
    }
    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }
    public function setStaticContentDir($dir)
    {
        $this->staticContentDir = $dir;
    }

    public function setTemplateDir($dir)
    {
        $this->templateDir = $dir;
        if ($this->mailer) {
            $this->mailer->setHtmlTemplate($dir . 'email.html');
        }
    }
    public function setDefaultParameters($params)
    {
        $this->content = array_merge($this->content, $params);
    }

    public function setLanguages($lang)
    {
        if (!is_array($lang)) return false;
        $languages = array();
        foreach ($lang as $l) {
            if (preg_match('/^[a-z][a-z]$/', $l) == 1) {
                $languages[] = $l;
            }
        }
        if (count($languages) > 0) {
            $this->default_language = $lang[0];
            $this->content['language'] = $this->default_language;
            $this->languages = $languages;
        }
    }

    public function setSubDir($real, $localhost = '')
    {
        if ($this->isLocalhost()) {
            $localhost = $localhost == '' ? $real : $localhost;
            $this->subdir = $localhost;
            $this->ssl = '';
        } else {
            $this->subdir = $real;
            $this->ssl = 's';
        }
    }

    public function setDomain($domain, $localhost_alternative = 'localhost')
    {
        if ($this->isLocalhost()) {
            $this->domain = $localhost_alternative;
        } else {
            $this->domain = $domain;
        }
    }

    public function registerMenuItem($link, $name)
    {
        if (substr($link, 0, 8) == 'https://' || substr($link, 0, 7) == 'http://') {
            $url = $link;
        } else {
            $url = $this->getAbsoluteURL('$$/' . $link);
        }
        if (!is_array($name)) {
            $n = array();
            foreach ($this->languages as $l) {
                $n[$l] = $name;
            }
            $name = $n;
        }
        foreach ($name as $lang => $title) {
            if (!isset($this->menu[$lang])) $this->menu[$lang] = array();
            $this->menu[$lang][] = array(
                'link' => str_replace('/$$/', '/' . $lang . '/', $url),
                'title' => $title,
            );
        }
    }

    public function registerURL($slug, $params = array(), $smartController = null, $method = '', $lang = array())
    {
        if (count($lang) == 0) { // if no languages are given >> take all languages + no language
            $lang = $this->languages;
            $iterator = $lang;
            array_unshift($iterator, '');
        } else {
            $iterator = $lang;
        }
        foreach ($iterator as $l) {
            $url = $this->subdir . $l . ($l != '' ? '/' : '') . $slug;
            $p = array();
            $k = 1;
            foreach ($params as $key => $default) {
                $p[$k++] = array('name' => $key, 'default' => $default);
            }
            $this->urls[$url] = array(
                'slug' => $slug,
                'params' => $p,
                'controller' => $smartController == null ? $this->md : $smartController,
                'method' => $method == '' ? 'view' : $method,
                'language' => $l,
            );
        }
    }

    public function go($overwrite = '', $debug = false)
    {

        // DEFAULT CONVERTOR
        $md = new StaticContentController($this->staticContentDir, $this);
        foreach ($this->languages as $l) {
            $this->registerURL('(.*)', array('code' => 'home', 'language' => $l), $md, 'view', array($l));
        }
        $this->registerURL('(.*)', array('code' => 'home', 'language' => $this->default_language), $md, 'view');

        // ADAPT FOR SUB DIRECTORY
        if ($overwrite == '') {
            if (!isset($_SERVER['REDIRECT_URL'])) $_SERVER['REDIRECT_URL'] = '';
            $request = $_SERVER['REDIRECT_URL'];
            if (strlen($request) < strlen($this->subdir)) $request = $this->subdir . $request;
            if (substr($request, -1, 1) != '/') $request .= '/';
        } else {
            $request = $this->subdir . $overwrite;
        }

        // FIND the FIRST MATCH
        foreach ($this->urls as $pattern => $info) {
            if (preg_match('!^' . $pattern . '/?$!i', $request, $matches) == 1) {
                $object = new \stdClass;
                foreach ($info['params'] as $index => $param) {
                    $name = $param['name'];
                    if (isset($matches[$index]) && $matches[$index] != '') {
                        $object->$name = $matches[$index];
                    } else {
                        $object->$name = $param['default'];
                        $this->content[$name] = $param['default'];
                    }
                }
                $lang = $this->default_language;
                if (isset($info['language']) && $info['language'] != '') {
                    $lang = $info['language'];
                }
                $this->content['language'] = $lang;
                if (isset($this->menu[$lang])) {
                    $this->content['site']['menu'] = $this->menu[$lang];
                }
                $this->content['site']['request'] = $request;
                $this->content['site']['home'] = '//' . $this->domain . $this->subdir;
                if (substr($request, 0, strlen($this->subdir)) == $this->subdir) {
                    $request_adapted = substr($request, strlen($this->subdir));
                } else {
                    $request_adapted = $request;
                }
                $this->content['page']['url'] = $this->getAbsoluteURL($request_adapted, true);
                $result = $info['controller']->{$info['method']}($object);
                break;
            }
        }

        // CHECK RESULTS and RENDER APPROPRIATELY
        if (\is_array($result) && isset($result['type'])) {
            if ($this->stats) {
                $this->stats->process(isset($result['stats']) ? $result['stats'] : $request, $lang, $this->content['navigator'], $request);
            }
            $render_type = $result['type'];
            if ($render_type == 'html') {
                if (isset($result['http_response_code']) && \is_numeric($result['http_response_code'])) {
                    http_response_code($result['http_response_code']);
                }
                $render = $this->render($result);
                if ($render != '') {
                    echo $render;
                }
            }
        }
    }

    private function isLocalhost($whitelist = ['127.0.0.1', '::1'])
    {
        return in_array($_SERVER['REMOTE_ADDR'], $whitelist);
    }

    private function processForm()
    {
        $f = $this->content['form'];
        $HASH = preg_replace('/[^a-z0-9]/', '', strtolower($this->content['site']['name']));
        $HASH = $HASH == '' ? 'ss' : $HASH;

        // parse opbouw
        foreach ($f['fields'] as $field => $param) {
            $info = array(
                'type' => $field == 'submit' ? 'submit' : 'text',
                'value' => htmlentities(
                    isset($_POST[$field . $HASH]) ? $_POST[$field . $HASH] : (isset($_GET[$field]) ? $_GET[$field] : '')
                ),
                'label' => '',
                'required' => false,
                'error' => false,
                'description' => '',
                'class' => '',
            );
            // if array is given >> copy the fields
            if (is_array($param)) {
                foreach ($param as $key => $value) {
                    if (array_key_exists($key, $info)) {
                        $info[$key] = $value;
                    }
                }
            } else {
                $info['label'] = trim('' . $param);
            }
            if (substr($info['label'], -1, 1) == '*') {
                $info['label'] = substr($info['label'], 0, -1);
                $info['required'] = true;
            }
            if ($info['type'] == 'checkbox') {
                $info['value'] = isset($_POST[$field . $HASH]) ? 1 : 0;
            }
            if ($info['type'] == 'submit') {
                $info['value'] = $info['label'];
                $info['class'] .= ' btn';
            } else if ($info['type'] == 'textarea') {
                $info['rows'] = 5;
                $info['cols'] = 25;
            }
            $f['fields'][$field] = $info;
        }

        // validate
        $correct = 0;
        foreach ($f['fields'] as $field => $info) {
            $error = false;
            if ($info['type'] == 'email' && $info['value'] != '') {
                if (!filter_var($info['value'], FILTER_VALIDATE_EMAIL)) {
                    $error = true;
                }
            }
            if ($info['required'] && isset($_POST[$field . $HASH]) && trim($info['value']) == '') {
                $error = true;
            }
            if ($info['required'] && $info['type'] == 'checkbox' && $info['value'] != 1) {
                $error = true;
            }
            $f['fields'][$field]['error'] = $error;
            if (!$error) {
                $correct++;
            }
        }
        $token = isset($_POST) && isset($_POST['token']) ? $_POST['token'] : '';

        // submit if all is well && user has submitted (never on first show)
        if (isset($_POST['submit' . $HASH]) && $correct == count($f['fields']) && $_SESSION['token'] == $token) {
            $database = Database::getInstance();
            $cols = '';
            $values = '';
            foreach ($f['fields'] as $field => $info) {
                if ($info['type'] == 'submit') continue;
                $cols .= ($cols != '' ? ', ' : '') . $field;
                $values .= ($values != '' ? ', ' : '') . ':' . $field;
            }
            $spam = isset($_POST) && isset($_POST['email']) ? ($_POST['email'] != '' ? 1 : 0) : 2;
            $cols .= ', spam';
            $values .= ', :spam';
            $SQL = 'INSERT INTO `' . $f['table'] . '` (' . $cols . ') VALUES (' . $values . ') ';
            $stmt = $database->prepare($SQL);
            foreach ($f['fields'] as $field => $info) {
                if ($info['type'] == 'submit') continue;
                $stmt->bindParam(':' . $field, $info['value'], $info['type'] != 'checkbox' ? \PDO::PARAM_STR : \PDO::PARAM_INT);
            }
            $stmt->bindParam(':spam', $spam, \PDO::PARAM_INT);
            $result = $stmt->execute();
            if ($result) {
                $error = false;
                if ($spam == 0 && isset($f['mailto'])) {
                    if (isset($f['message'])) {
                        $message = $f['message'];
                    } else {
                        $message = "# Webformulier <span>" . $this->content['title'] . "</span> \n\n";
                        $message .= "Er is een nieuwe reactie op dit formulier: \n\n";
                        foreach ($f['fields'] as $field => $info) {
                            if ($info['type'] == 'submit') continue;
                            $message .= '- ' . $field . ': ' . $info['value'] . "\n";
                        }
                        $message .= "\n\n";
                    }

                    $headers = array();
                    $headers['MIME-Version'] = '1.0';
                    $headers['Content-type:'] = 'text/html;charset=UTF-8';
                    $CC = isset($f['mailcc']) ? $f['mailcc'] : '';
                    $TO = isset($f['mailto']) ? $f['mailto'] : '';
                    $ATTACH = isset($f['attachment']) ? $f['attachment'] : '';
                    foreach ($f['fields'] as $field => $info) {
                        if ($info['type'] == 'submit') continue;
                        $message = str_replace('<' . $field . '>', $info['value'], $message);
                        $TO = str_replace('<' . $field . '>', $info['value'], $TO);
                        $CC = str_replace('<' . $field . '>', $info['value'], $CC);
                    }
                    $mailOK = $this->mailer->mail($TO, $this->content['title'], $message, $headers, true, $CC, $ATTACH);
                    if (!$mailOK) {
                        $error = true;
                    }
                }
                if (!$error) {
                    unset($this->content['form']);
                    header('Location: ' . $this->getAbsoluteURL($f['redirect'], true));
                    exit;
                }
            }
        }

        // create html
        $html = '';
        $html .= '<form method="post" id="' . $f['table'] . '">' . "\n";
        $_SESSION['token'] = md5(uniqid(mt_rand(), true));
        $html .= '<input type="hidden" name="token" value="' . $_SESSION['token'] . '">';
        $html .= '<label class="ohnohoney" for="email"></label><input class="ohnohoney" role="presentation" autocomplete="off" type="email" id="email" name="email" placeholder="Your email here">';
        foreach ($f['fields'] as $field => $info) {
            if ($info['error']) $info['class'] .= ' error';
            $html .= '<div>';
            $label = '<label class="' . $info['type'] . '" for="' . $field . $HASH . '">' . $info['label'];
            if ($info['required']) $label .= ' <span class="required">*</span>';
            $label .= '</label>';
            if ($info['type'] == 'textarea') {
                $element = '<textarea ' . (trim($info['class']) != '' ? 'class="' . trim($info['class']) . '" ' : '') . 'id="' . $field . $HASH . '" name="' . $field . $HASH . '" rows="' . $info['rows'] . '" cols="' . $info['cols'] . '">' . $info['value'] . '</textarea>';
            } else {
                $element = '<input type="' . $info['type'] . '" ' . (trim($info['class']) != '' ? 'class="' . trim($info['class']) . '" ' : '') . 'id="' . $field . $HASH . '" name="' . $field . $HASH . '" value="' . $info['value'] . '" />';
            }

            if ($info['type'] == 'submit') {
                $html .= $element;
            } else if ($info['type'] == 'checkbox') {
                $html .= $element . ' ' . $label;
            } else {
                $html .= $label . ' ' . $element;
            }
            $html .= '</div>';
            $html .= "\n";
        }
        $html .= '</form>';

        // put in content
        $this->content['form']['html'] = $html;
    }

    private function render($input)
    {
        // SPLIT MARKDOWN AND YAML
        $everything = substr($input['yaml+md'], 3);
        $yaml = strstr($everything, '---', true);
        $markdown = substr($everything, strlen($yaml) + 3);
        $this->content = array_merge($this->content, Spyc::YAMLLoad($yaml));

        // SETUP MARKDOWN // CommonMark and Extensions
        $environment = new Environment(array());
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AttributesExtension());
        $converter = new MarkdownConverter($environment);

        // COMBINE input and yaml INTO contents
        foreach ($input as $key => $value) {
            $this->content[$key] = $value;
        }

        // CHECK for FORM
        if (isset($this->content['form'])) {
            $this->processForm();
            if (isset($this->content['redirect']) && $this->content['redirect'] != '') return '';
        }

        // PARSE the MARKDOWN CONTENTS in HTML
        $template = new Template();
        $template->parse($markdown);
        $markdown_with_liquid_replacements = $template->render($this->content);
        $this->content['html'] = '' . $converter->convert($markdown_with_liquid_replacements);

        // GET the TEMPLATE with INLINE BLOCKS
        $liq = file_get_contents($this->templateDir . $this->content['template'] . '.html');
        $recursion = 0;
        while (strpos($liq, '{% include ') !== false && $recursion < 5) {
            $recursion++;
            $liq = preg_replace_callback('/{% include ([^\.]*.html) %}/', array(&$this, 'inlineTemplate'), $liq);
        }

        // PARSE CONTENT into TEMPLATE
        $template = new Template();
        $template->parse($liq);
        $render = $template->render($this->content);
        $t2 = new Template();
        $t2->parse($render);
        $render = $t2->render($this->content);

        // SECTION SEPARATORS
        $render = preg_replace($this->sectionSeparators['find'], $this->sectionSeparators['replace'], $render);

        // MAKE ABSOLUTE URLs for IMAGES, and HYPERLINKS
        $absolute = $this->getAbsoluteURL();
        $render = str_replace(' href="./', ' href="' . $absolute, $render);
        $render = str_replace(' src="./', ' src="' . $absolute, $render);
        $render = str_replace(' data="./', ' data="' . $absolute, $render);
        $render = str_replace(' content="./', ' content="' . $absolute, $render);

        // INLINE CSS
        $render = preg_replace_callback('/<link href="' . str_replace('/', '.', $absolute) . '([^"]*)"[^>]*>/', array(&$this, 'inlineCss'), $render);
        // only after CSS inline and before optimise
        $render = str_replace(" url('./", " url('" . $absolute, $render);
        $render = preg_replace_callback('/<style([^>]*)>([^<]*)<.style>/', array(&$this, 'optimiseCss'), $render);
        // $render = preg_replace('/>\s+</m', '><', $render);

        $render = preg_replace('!<img ([^>]*) />!', '<img \1>', $render);
        $render = preg_replace('!<img ([^>]*)/>!', '<img \1>', $render);

        return $render;
    }

    private function inlineTemplate($matches)
    {
        $template = file_get_contents($this->templateDir . $matches[1]);
        return $template;
    }

    private function inlineCss($matches)
    {
        $css = file_get_contents('./' . $matches[1]);
        if (isset($_SERVER['PTRNONCE'])) return '<style nonce="' . $_SERVER['PTRNONCE'] . '">' . $css . '</style>';
        else return '<style>' . $css . '</style>';
    }

    private function optimiseCss($matches)
    {
        $css = $matches[2];
        $css = preg_replace('!/\*[^\*]+\*/!', '', $css);
        $css = preg_replace('/\s*([{},:;@])\s*/', '\1', $css);
        $css = preg_replace('/^\s*/', '', $css);
        $css = preg_replace('/\s*$/', '', $css);
        return '<style' . $matches[1] . '>' . $css . '</style>';
    }
    public function getLanguages()
    {
        return $this->languages;
    }
    public function getContent()
    {
        return $this->content;
    }
    public function getSubDir()
    {
        return $this->subdir;
    }
    public function getDomain()
    {
        return $this->domain;
    }
    public function getContentDir()
    {
        return $this->staticContentDir;
    }
    public function setSectionSeparators($find, $replace)
    {
        $this->sectionSeparators = array('find' => $find, 'replace' => $replace);
    }
    public function getAbsoluteURL($url = '', $scheme = false)
    {
        $protocol = '';
        if ($scheme) {
            $protocol = 'https';
            if (isset($_SERVER['REQUEST_SCHEME'])) {
                if ($_SERVER['REQUEST_SCHEME'] == 'https' || $_SERVER['REQUEST_SCHEME'] == 'http') {
                    $protocol = $_SERVER['REQUEST_SCHEME'];
                }
            }
            $protocol .= ':';
        }
        return $protocol . '//' .  $_SERVER['SERVER_NAME'] . $this->subdir . $url;
    }

    public function processCookies()
    {
        // if we don't know anything: it's also a reset
        $allowed_cookies = array('reset');
        // get what's in the session
        if (isset($_SESSION) && isset($_SESSION['gdpr']) && isset($_SESSION['gdpr']['cookies'])) {
            $allowed_cookies = $_SESSION['gdpr']['cookies'];
            $key = array_search('information', $allowed_cookies, true);
            if ($key !== false) {
                unset($allowed_cookies[$key]);
            }
        }
        // if there's a POST >> overwrite
        if (isset($_POST['gdpr_cookies_save']) || isset($_POST['gdpr_cookies_accept_all'])) {
            $allowed_cookies = array('necessary');
            foreach (array('preference', 'statistics', 'marketing') as $type) {
                if (isset($_POST['gdpr_cookies_' . $type]) || isset($_POST['gdpr_cookies_accept_all'])) {
                    $allowed_cookies[] = $type;
                }
            }
        }
        // on RESET >> remove from session, otherwise, put it in the session
        if (isset($_POST['gdpr_cookies_reset'])) {
            $allowed_cookies[] = 'reset';
        }
        if (isset($_GET['gdpr_cookies_information']) && !(isset($_POST['gdpr_cookies_save']) || isset($_POST['gdpr_cookies_accept_all']))) {
            $allowed_cookies[] = 'information';
        }
        // in all cases: put the current choices in CONTENT
        $this->content['gdpr'] = array();
        $this->content['gdpr']['cookies'] = $allowed_cookies;
        if (!isset($_SESSION['gdpr'])) $_SESSION['gdpr'] = array();
        $_SESSION['gdpr']['cookies'] = $allowed_cookies;
    }
}
