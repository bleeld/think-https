<?php

declare(strict_types=1);

namespace think\http\Crawler;

use think\http\Response;

/**
 * 爬取页面解析类
 * 
 * 提供 HTML 解析、链接提取、文本提取、CSS 选择器等功能
 */
class CrawlPage
{
    protected string $url;
    protected Response $response;
    protected string $encoding;
    protected ?string $html = null;
    protected ?\DOMDocument $dom = null;
    protected ?\DOMXPath $xpath = null;

    public function __construct(string $url, Response $response, string $encoding = 'UTF-8')
    {
        $this->url = $url;
        $this->response = $response;
        $this->encoding = $encoding;
    }

    /**
     * 获取原始响应
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * 获取 URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * 获取 HTTP 状态码
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * 获取页面 HTML
     */
    public function getHtml(): string
    {
        if ($this->html === null) {
            $this->html = $this->response->getBody();
            // 尝试自动检测编码并转为 UTF-8
            if ($this->encoding !== 'UTF-8') {
                $this->html = mb_convert_encoding($this->html, 'UTF-8', $this->encoding);
            }
        }
        return $this->html;
    }

    /**
     * 获取 DOMDocument
     */
    public function getDom(): \DOMDocument
    {
        if ($this->dom === null) {
            $this->dom = new \DOMDocument();
            $html = $this->getHtml();
            // 抑制 HTML 解析警告
            @$this->dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING
            );
        }
        return $this->dom;
    }

    /**
     * 获取 DOMXPath
     */
    public function getXpath(): \DOMXPath
    {
        if ($this->xpath === null) {
            $this->xpath = new \DOMXPath($this->getDom());
        }
        return $this->xpath;
    }

    /**
     * 获取页面标题
     */
    public function getTitle(): string
    {
        $nodes = $this->getXpath()->query('//title');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }

    /**
     * 获取 meta description
     */
    public function getDescription(): string
    {
        $nodes = $this->getXpath()->query('//meta[@name="description"]/@content');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }
        return '';
    }

    /**
     * 获取 meta keywords
     */
    public function getKeywords(): string
    {
        $nodes = $this->getXpath()->query('//meta[@name="keywords"]/@content');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }
        return '';
    }

    /**
     * 提取页面中所有链接
     * 
     * @return string[]
     */
    public function getLinks(): array
    {
        $links = [];
        $nodes = $this->getXpath()->query('//a[@href]/@href');

        for ($i = 0; $i < $nodes->length; $i++) {
            $href = trim($nodes->item($i)->nodeValue);
            if (!empty($href)) {
                $links[] = $href;
            }
        }

        return array_unique($links);
    }

    /**
     * 提取所有图片 URL
     * 
     * @return string[]
     */
    public function getImages(): array
    {
        $images = [];
        $nodes = $this->getXpath()->query('//img[@src]/@src');

        for ($i = 0; $i < $nodes->length; $i++) {
            $src = trim($nodes->item($i)->nodeValue);
            if (!empty($src)) {
                $images[] = $src;
            }
        }

        return array_unique($images);
    }

    /**
     * 提取纯文本内容（去除 script/style）
     */
    public function getText(): string
    {
        $dom = $this->getDom();

        // 移除 script 和 style
        $removeTags = ['script', 'style', 'noscript'];
        foreach ($removeTags as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $el) {
                $el->parentNode->removeChild($el);
            }
        }

        $text = $dom->textContent;
        // 清理空白
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * 提取表格数据
     * 
     * @return array[] 每行一个关联数组
     */
    public function getTables(): array
    {
        $tables = [];
        $tableNodes = $this->getXpath()->query('//table');

        for ($t = 0; $t < $tableNodes->length; $t++) {
            $table = [];
            $headers = [];

            // 提取表头
            $thNodes = $this->getXpath()->query('.//th', $tableNodes->item($t));
            for ($i = 0; $i < $thNodes->length; $i++) {
                $headers[] = trim($thNodes->item($i)->textContent);
            }

            // 提取行
            $trNodes = $this->getXpath()->query('.//tr', $tableNodes->item($t));
            for ($r = 0; $r < $trNodes->length; $r++) {
                $row = [];
                $tdNodes = $this->getXpath()->query('.//td', $trNodes->item($r));
                for ($c = 0; $c < $tdNodes->length; $c++) {
                    $value = trim($tdNodes->item($c)->textContent);
                    if (!empty($headers) && isset($headers[$c])) {
                        $row[$headers[$c]] = $value;
                    } else {
                        $row[] = $value;
                    }
                }
                if (!empty($row)) {
                    $table[] = $row;
                }
            }

            if (!empty($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * CSS 选择器查询（简化版）
     * 
     * 支持：tag, .class, #id, tag.class, tag[attr], tag[attr=value]
     * 
     * @return \DOMNodeList
     */
    public function query(string $selector): \DOMNodeList
    {
        $xpathExpr = $this->cssToXpath($selector);
        return $this->getXpath()->query($xpathExpr);
    }

    /**
     * CSS 选择器查询，返回节点数组
     */
    public function findAll(string $selector): array
    {
        $nodes = $this->query($selector);
        $result = [];
        for ($i = 0; $i < $nodes->length; $i++) {
            $result[] = $nodes->item($i);
        }
        return $result;
    }

    /**
     * CSS 选择器查询，返回第一个节点
     */
    public function findFirst(string $selector): ?\DOMNode
    {
        $nodes = $this->query($selector);
        return $nodes->length > 0 ? $nodes->item(0) : null;
    }

    /**
     * 提取节点文本
     */
    public function text(string $selector): string
    {
        $node = $this->findFirst($selector);
        return $node ? trim($node->textContent) : '';
    }

    /**
     * 提取节点属性
     */
    public function attr(string $selector, string $attribute): string
    {
        $node = $this->findFirst($selector);
        if ($node instanceof \DOMElement) {
            return $node->getAttribute($attribute);
        }
        return '';
    }

    /**
     * 简化 CSS 选择器转 XPath
     */
    protected function cssToXpath(string $selector): string
    {
        $selector = trim($selector);

        // ID 选择器: #id
        if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
            return "//*[@id='{$m[1]}']";
        }

        // Class 选择器: .class
        if (preg_match('/^\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }

        // 标签选择器: tag
        if (preg_match('/^(\w+)$/', $selector, $m)) {
            return "//{$m[1]}";
        }

        // 标签+Class: tag.class
        if (preg_match('/^(\w+)\.([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }

        // 标签+ID: tag#id
        if (preg_match('/^(\w+)#([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[@id='{$m[2]}']";
        }

        // 属性存在: tag[attr]
        if (preg_match('/^(\w+)\[(\w+)\]$/', $selector, $m)) {
            return "//{$m[1]}[@{$m[2]}]";
        }

        // 属性等于: tag[attr=value]
        if (preg_match('/^(\w+)\[(\w+)=["\']?([^"\']+)["\']?\]$/', $selector, $m)) {
            return "//{$m[1]}[@{$m[2]}='{$m[3]}']";
        }

        // 后代选择器: A B (简化处理)
        if (strpos($selector, ' ') !== false) {
            $parts = preg_split('/\s+/', $selector);
            $xpath = '';
            foreach ($parts as $part) {
                $xpath .= '//' . $this->cssToXpath($part);
            }
            // 移除开头的 //
            return '/' . ltrim($xpath, '/');
        }

        // 默认返回原选择器作为 XPath
        return "//{$selector}";
    }
}
