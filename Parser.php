<?php


class ParseMarkDown
{
    /**
     * _whiteList
     *
     * @var string
     */
    public $_commonWhiteList = 'kbd|b|i|strong|em|sup|sub|br|code|del|a|hr|small';

    /**
     * _specialWhiteList
     *
     * @var mixed
     * @access private
     */
    private $_specialWhiteList = array(
        'table'  =>  'table|tbody|thead|tfoot|tr|td|th'
    );

    /**
     * _footnotes
     *
     * @var array
     */
    public $_footnotes;

    /**
     * _blocks
     *
     * @var array
     */
    private $_blocks;

    /**
     * _current
     *
     * @var string
     */
    private $_current;

    /**
     * _pos
     *
     * @var int
     */
    private $_pos;

    /**
     * _definitions
     *
     * @var array
     */
    public $_definitions;

    /**
     * @var array
     */
    private $_hooks = array();

    /**
     * @var array
     */
    private $_holders;

    /**
     * @var string
     */
    private $_uniqid;

    /**
     * @var int
     */
    private $_id;

    /**
     * makeHtml
     *
     * @param mixed $text
     * @return string
     */
    public function makeHtml($text)
    {
        $this->_footnotes = array();
        $this->_definitions = array();
        $this->_holders = array();
        $this->_uniqid = md5(uniqid());
        $this->_id = 0;

        $text = $this->initText($text);  //将\t \r替换为4空格和空字符

        $this->show($text);

        $html = $this->parse($text);
        $html = $this->makeFootnotes($html);

        return $this->call('makeHtml', $html);
    }

    function show($var=NULL,$ty=0){
        echo '<br/>';
        echo '<hr/>';

        if(!is_null($var)){
            if($ty ==0)
            echo $var;

            if($ty == 1)
                print_r($var);

            if($ty == 2)
                var_dump($var);
        }
        echo '<hr/>';
        echo '<br/>';
    }
    /**
     * @param $type
     * @param $callback
     */
    public function hook($type, $callback)
    {
        $this->_hooks[$type][] = $callback;
    }

    /**
     * @param $str
     * @return string
     */
    public function makeHolder($str)
    {
        $key = "|\r" . $this->_uniqid . $this->_id . "\r|";
        $this->_id ++;
        $this->_holders[$key] = $str;

        //$this->show($this->_holders,2);
        return $key;
    }

    /**
     * @param $text
     * @return mixed
     */
    private function initText($text)
    {
        $text = str_replace(array("\t", "\r"),  array('    ', ''),  $text);
        return $text;
    }

    /**
     * @param $html
     * @return string
     */
    private function makeFootnotes($html)
    {
        if (count($this->_footnotes) > 0) {
            $html .= '<div class="footnotes"><hr><ol>';
            $index = 1;

            while ($val = array_shift($this->_footnotes)) {
                if (is_string($val)) {
                    $val .= " <a href=\"#fnref-{$index}\" class=\"footnote-backref\">&#8617;</a>";
                } else {
                    $val[count($val) - 1] .= " <a href=\"#fnref-{$index}\" class=\"footnote-backref\">&#8617;</a>";
                    $val = count($val) > 1 ? $this->parse(implode("\n", $val)) : $this->parseInline($val[0]);
                }

                $html .= "<li id=\"fn-{$index}\">{$val}</li>";
                $index ++;
            }

            $html .= '</ol></div>';
        }

        return $html;
    }

    /**
     * parse
     *
     * @param string $text
     * @return string
     */
    private function parse($text)       //存在多处调用
    {
        $blocks = $this->parseBlock($text, $lines);
        $html = '';

       // $this->show($blocks,2);
        foreach ($blocks as $block) {
            //块类型，开始行，结束行，值
            list ($type, $start, $end, $value) = $block;
            $extract = array_slice($lines, $start, $end - $start + 1);  //提取块中的元素，仍然为数组
            $method = 'parse' . ucfirst($type); //首字母转换为大写

            $extract = $this->call('before' . ucfirst($method), $extract, $value);
            $result = $this->{$method}($extract, $value);
            $result = $this->call('after' . ucfirst($method), $result, $value);

            $html .= $result;
        }

        return $html;
    }

    /**
     * @param $type
     * @param $value
     * @return mixed
     */
    private function call($type, $value)
    {
        if (empty($this->_hooks[$type])) {
            return $value;
        }

        $args = func_get_args();   //获取所有传递进来的函数形参
        $args = array_slice($args, 1); //返回第二个参数开始的所有元素

        foreach ($this->_hooks[$type] as $callback) {
            $value = call_user_func_array($callback, $args);
            $args[0] = $value;
        }

        return $value;
    }

    /**
     * @param $text
     * @param $clearHolders
     * @return string
     */
    private function releaseHolder($text, $clearHolders = true)
    {
        $deep = 0;
        while (strpos($text, "|\r") !== false && $deep < 10) {
            $text = str_replace(array_keys($this->_holders), array_values($this->_holders), $text);
            $deep ++;
        }

        if ($clearHolders) {
            $this->_holders = array();
        }

        return $text;
    }

    /**
     * parseInline
     *
     * @param string $text
     * @param string $whiteList
     * @param bool $clearHolders
     * @param bool $enableAutoLink
     * @return string
     */
    public function parseInline($text, $whiteList = '', $clearHolders = true, $enableAutoLink = true)
    {
        $self = $this;
        $text = $this->call('beforeParseInline', $text);

        // code
        $text = preg_replace_callback(
            "/(^|[^\\\])(`+)(.+?)\\2/",             //匹配代码标记在一行的
            function ($matches) use ($self) {       //use从父作用域中继承变量
                return  $matches[1] . $self->makeHolder(
                    '<code>' . htmlspecialchars($matches[3]) . '</code>'        //不解析html标签
                );
            },
            $text
        );


        // link
        $text = preg_replace_callback(
            "/<(https?:\/\/.+)>/i",                //匹配<http://a>
            function ($matches) use ($self) {
                //$this->show($matches,2);
                $url = $self->cleanUrl($matches[1]);
                $link = $self->call('parseLink', $matches[1]);

                return $self->makeHolder(
                    "<a href=\"{$url}\">{$link}</a>"
                );
            },
            $text
        );

        // encode unsafe tags
        $text = preg_replace_callback(
            "/<(\/?)([a-z0-9-]+)(\s+[^>]*)?>/i",
            function ($matches) use ($self, $whiteList) {
                $this->show($matches,2);
                if (false !== stripos(        //查找字符串首次出现的位置，不区分大小写
                    '|' . $self->_commonWhiteList . '|' . $whiteList . '|',
                        '|' . $matches[2] . '|'
                )) {

                    return $self->makeHolder($matches[0]);
                } else {
                    return htmlspecialchars($matches[0]);
                }
            },
            $text
        );


        $text = str_replace(array('<', '>'),  array('&lt;', '&gt;'),  $text);

        // footnote
        $text = preg_replace_callback(
            "/\[\^((?:[^\]]|\\\\\]|\\\\\[)+?)\]/",
            function ($matches) use ($self) {
                $id = array_search($matches[1], $self->_footnotes);

                if (false === $id) {
                    $id = count($self->_footnotes) + 1;
                    $self->_footnotes[$id] = $self->parseInline($matches[1], '', false);
                }

                return $self->makeHolder(
                    "<sup id=\"fnref-{$id}\"><a href=\"#fn-{$id}\" class=\"footnote-ref\">{$id}</a></sup>"
                );
            },
            $text
        );

        // image
        $text = preg_replace_callback(
            "/!\[((?:[^\]]|\\\\\]|\\\\\[)*?)\]\(((?:[^\)]|\\\\\)|\\\\\()+?)\)/",
            function ($matches) use ($self) {
                $escaped = $self->escapeBracket($matches[1]);
                $url = $self->escapeBracket($matches[2]);
                $url = $self->cleanUrl($url);
                return $self->makeHolder(
                    "<img src=\"{$url}\" alt=\"{$escaped}\" title=\"{$escaped}\">"
                );
            },
            $text
        );

        $text = preg_replace_callback(
            "/!\[((?:[^\]]|\\\\\]|\\\\\[)*?)\]\[((?:[^\]]|\\\\\]|\\\\\[)+?)\]/",
            function ($matches) use ($self) {
                $escaped = $self->escapeBracket($matches[1]);

                $result = isset( $self->_definitions[$matches[2]] ) ?
                    "<img src=\"{$self->_definitions[$matches[2]]}\" alt=\"{$escaped}\" title=\"{$escaped}\">"
                    : $escaped;

                return $self->makeHolder($result);
            },
            $text
        );

        // link
        $text = preg_replace_callback(
            "/\[((?:[^\]]|\\\\\]|\\\\\[)+?)\]\(((?:[^\)]|\\\\\)|\\\\\()+?)\)/",
            function ($matches) use ($self) {
                $escaped = $self->parseInline(
                    $self->escapeBracket($matches[1]),  '',  false, false
                );
                $url = $self->escapeBracket($matches[2]);
                $url = $self->cleanUrl($url);
                return $self->makeHolder("<a href=\"{$url}\">{$escaped}</a>");
            },
            $text
        );

        $text = preg_replace_callback(
            "/\[((?:[^\]]|\\\\\]|\\\\\[)+?)\]\[((?:[^\]]|\\\\\]|\\\\\[)+?)\]/",
            function ($matches) use ($self) {
                $escaped = $self->parseInline(
                    $self->escapeBracket($matches[1]),  '',  false
                );
                $result = isset( $self->_definitions[$matches[2]] ) ?
                    "<a href=\"{$self->_definitions[$matches[2]]}\">{$escaped}</a>"
                    : $escaped;

                return $self->makeHolder($result);
            },
            $text
        );

        // escape
        $text = preg_replace_callback(
            "/\\\(x80-xff|.)/",
            function ($matches) use ($self) {
                $escaped = htmlspecialchars($matches[1]);
                $escaped = str_replace('$', '&dollar;', $escaped);
                return  $self->makeHolder($escaped);
            },
            $text
        );

        // strong and em and some fuck
        $text = $this->parseInlineCallback($text);
        $text = preg_replace(
            "/<([_a-z0-9-\.\+]+@[^@]+\.[a-z]{2,})>/i",
            "<a href=\"mailto:\\1\">\\1</a>",
            $text
        );

        // autolink url
        if ($enableAutoLink) {
            $text = preg_replace_callback(
                "/(^|[^\"])((https?):[x80-xff_a-z0-9-\.\/%#@\?\+=~\|\,&\(\)]+)($|[^\"])/i",
                function ($matches) use ($self) {
                    $link = $self->call('parseLink', $matches[2]);
                    return "{$matches[1]}<a href=\"{$matches[2]}\">{$link}</a>{$matches[4]}";
                },
                $text
            );
        }

        $text = $this->call('afterParseInlineBeforeRelease', $text);
        $text = $this->releaseHolder($text, $clearHolders);

        $text = $this->call('afterParseInline', $text);

        return $text;
    }

    /**
     * @param $text
     * @return mixed
     */
    public function parseInlineCallback($text)
    {
        $self = $this;

        $text = preg_replace_callback(
            "/(\*{3})(.+?)\\1/",
            function ($matches) use ($self) {
                return  '<strong><em>' .
                    $self->parseInlineCallback($matches[2]) .
                    '</em></strong>';
            },
            $text
        );

        $text = preg_replace_callback(
            "/(\*{2})(.+?)\\1/",
            function ($matches) use ($self) {
                return  '<strong>' .
                    $self->parseInlineCallback($matches[2]) .
                    '</strong>';
            },
            $text
        );

        $text = preg_replace_callback(
            "/(\*)(.+?)\\1/",
            function ($matches) use ($self) {
                return  '<em>' .
                    $self->parseInlineCallback($matches[2]) .
                    '</em>';
            },
            $text
        );

        $text = preg_replace_callback(
            "/(\s+|^)(_{3})(.+?)\\2(\s+|$)/",
            function ($matches) use ($self) {
                return  $matches[1] . '<strong><em>' .
                    $self->parseInlineCallback($matches[3]) .
                    '</em></strong>' . $matches[4];
            },
            $text
        );

        $text = preg_replace_callback(
            "/(\s+|^)(_{2})(.+?)\\2(\s+|$)/",
            function ($matches) use ($self) {
                return  $matches[1] . '<strong>' .
                    $self->parseInlineCallback($matches[3]) .
                    '</strong>' . $matches[4];
            },
            $text
        );

        $text = preg_replace_callback(
            "/(\s+|^)(_)(.+?)\\2(\s+|$)/",
            function ($matches) use ($self) {
                return  $matches[1] . '<em>' .
                    $self->parseInlineCallback($matches[3]) .
                    '</em>' . $matches[4];
            },
            $text
        );

        $text = preg_replace_callback(
            "/(~{2})(.+?)\\1/",
            function ($matches) use ($self) {
                return  '<del>' .
                    $self->parseInlineCallback($matches[2]) .
                    '</del>';
            },
            $text
        );

        return $text;
    }

    /**
     * parseBlock
     *
     * @param string $text
     * @param array $lines
     * @return array
     */
    private function parseBlock($text, &$lines)
    {
        $lines = explode("\n", $text);      //按照换行符分割字符串为数组
        //$this->show($lines,1);
        $this->_blocks = array();
        $this->_current = 'normal';
        $this->_pos = -1;
        $special = implode("|", array_keys($this->_specialWhiteList)); //$special=table
        //echo $special.'<br/>';

        $emptyCount = 0;
        // analyze by line
        foreach ($lines as $key => $line) {
            $block = $this->getBlock();

            // code block is special
            //匹配以三个以上 ~|` 开始,但是不以 `结尾
            if (preg_match("/^(\s*)(~|`){3,}([^`~]*)$/i", $line, $matches)) {
                //$this->show($matches,1);
                if ($this->isBlock('code')) {      //判断当前处理类型
                    $isAfterList = $block[3][2];
                    if ($isAfterList) {              //$isAfterList为TRUE的话,将当前代码快合并到列表块中,删除当前代码快
                        $this->combineBlock()
                            ->setBlock($key);
                    } else {
                        $this->setBlock($key)       //此时有一种情况是,```后的内容,即代码类型,中间为空,下一次匹配就是结尾的```
                            ->endBlock();
                    }
                   // $this->show($this->_blocks[$this->_pos],1);
                } else {
                    $isAfterList = false;

                    if ($this->isBlock('list')) {
                        $space = $block[3];

                        $isAfterList = ($space > 0 && strlen($matches[1]) >= $space)  //如果上一块是列表,开头有空格或者当前代码块```前有空格
                            || strlen($matches[1]) > $space;
                    }

                    $this->startBlock('code', $key, array(
                        $matches[1],  $matches[3],  $isAfterList
                    ));
                   // $this->show($this->_blocks[$this->_pos],2);
                }

                continue;
            } else if ($this->isBlock('code')) {      //此时的情况是代码位于中间
                $this->setBlock($key);
                //$this->show($this->_blocks[$this->_pos],2);
                continue;
            }

            // html block is special too
            //匹配html的<table>标签,可以带属性
            if (preg_match("/^\s*<({$special})(\s+[^>]*)?>/i", $line, $matches)) {

                $tag = strtolower($matches[1]);    //$tag=<table>
                if (!$this->isBlock('html', $tag) && !$this->isBlock('pre')) {
                    $this->startBlock('html', $key, $tag);  //设置html块
                }

                continue;
            } else if (preg_match("/<\/({$special})>\s*$/i", $line, $matches)) {           //匹配</table>
               // $this->show($this->_blocks[$this->_pos],2);
                $tag = strtolower($matches[1]);

                if ($this->isBlock('html', $tag)) {
                    $this->setBlock($key)
                        ->endBlock();
                }
                continue;
            } else if ($this->isBlock('html')) {        //两个标签中间的内容
                $this->setBlock($key);
                continue;
            }

            switch (true) {
                // pre block
                //4个空格开始的块
                case preg_match("/^ {4}/", $line):
                    $emptyCount = 0;

                    if ($this->isBlock('pre') || $this->isBlock('list')) {
                        $this->setBlock($key);
                    } else if ($this->isBlock('normal')) {
                        $this->startBlock('pre', $key);
                       // $this->show($this->_current);
                    }
                    break;

                // list
                //匹配列表
                case preg_match("/^(\s*)((?:[0-9a-z]+\.)|\-|\+|\*)\s+/", $line, $matches):

                    //$this->show($matches,2);
                    $space = strlen($matches[1]);//匹配到的空格的个数
                    $emptyCount = 0;

                    // opened
                    if ($this->isBlock('list')) {
                        $this->setBlock($key, $space);
                    } else {
                        $this->startBlock('list', $key, $space);
                    }
                    break; 

                // footnote
                //[^] 定义注脚, [^]: 解释注脚
                case preg_match("/^\[\^((?:[^\]]|\\]|\\[)+?)\]:/", $line, $matches):   //前面不带空格的解释脚注
                   $this->show($matches,2);
                    $space = strlen($matches[0]) - 1;
                    $this->startBlock('footnote', $key, array(
                        $space,  $matches[1]
                    ));
                    break;

                // definition
                case preg_match("/^\s*\[((?:[^\]]|\\]|\\[)+?)\]:\s*(.+)$/", $line, $matches): //前面带空格的解释脚注,也可以匹配不带^号[f3]
                    //$this->show($matches,2);
                    $this->_definitions[$matches[1]] = $this->cleanUrl($matches[2]);
                    //$this->show( $this->_definitions[$matches[1]]);
                    $this->startBlock('definition', $key)
                        ->endBlock();
                      // $this->show($this->_blocks[$this->_pos],2);
                    break;

                // block quote
                //引用块
                case preg_match("/^\s*>/", $line):
                    if ($this->isBlock('quote')) {
                        $this->setBlock($key);
                    } else {
                        $this->startBlock('quote', $key);
                    }
                    break;

                // table
                case preg_match("/^((?:(?:(?:[ :]*\-[ :]*)+(?:\||\+))|(?:(?:\||\+)(?:[ :]*\-[ :]*)+)|(?:(?:[ :]*\-[ :]*)+(?:\||\+)(?:[ :]*\-[ :]*)+))+)$/", $line, $matches):
                   // $this->show($matches,2);
                    if ($this->isBlock('table')) {
                        $block[3][0][] = $block[3][2];
                        $block[3][2] ++;
                        $this->setBlock($key, $block[3]);
                    } else { 
                        $head = 0;

                        if (empty($block) ||
                            $block[0] != 'normal' ||
                            preg_match("/^\s*$/", $lines[$block[2]])) {
                            $this->startBlock('table', $key);
                        } else {
                            $head = 1;
                            $this->backBlock(1, 'table');
                            //从表头下一行开始匹配的,所以表头的key被算到了上一区块的结尾,此方法将上一区块结尾key减1,然后将表头的key,算作table块的开始key
                        }

                        if ($matches[1][0] == '|') {

                            $matches[1] = substr($matches[1], 1);      //去掉开始的\

                            if ($matches[1][strlen($matches[1]) - 1] == '|') {
                                $matches[1] = substr($matches[1], 0, -1);       //去掉末尾的\
                            }

                        }

                        $rows = preg_split("/(\+|\|)/", $matches[1]);     //按照\分割成数组
                        //$this->show($rows,1);
                        $aligns = array();
                        foreach ($rows as $row) {
                            $align = 'none';

                            if (preg_match("/^\s*(:?)\-+(:?)\s*$/", $row, $matches)) {  //判断--- 的开始和结尾是否有: ,从而判断位置
                                if (!empty($matches[1]) && !empty($matches[2])) {
                                    $align = 'center';      //两边都有,位于中央
                                } else if (!empty($matches[1])) {
                                    $align = 'left';       //左边有,位于左边
                                } else if (!empty($matches[2])) {
                                    $align = 'right';     //右边有,位于右边
                                }
                            }

                            $aligns[] = $align;
                        }

                        $this->setBlock($key, array(array($head), $aligns, $head + 1));
                    }
                    break;

                // single heading
                //#字标题
                case preg_match("/^(#+)(.*)$/", $line, $matches):
                    $num = min(strlen($matches[1]), 6);    //最多为6个#,6级标题
                    $this->startBlock('sh', $key, $num)
                        ->endBlock();
                    break;

                // multi heading
                //=== 表示一级标题, ---表示二级标题
                case preg_match("/^\s*((=|-){2,})\s*$/", $line, $matches)
                    && ($block && $block[0] == "normal" && !preg_match("/^\s*$/", $lines[$block[2]])):    // check if last line isn't empty
                    if ($this->isBlock('normal')) {
                        $this->backBlock(1, 'mh', $matches[1][0] == '=' ? 1 : 2)   //== 和 -- 上一行为标题文字,需要回退
                            ->setBlock($key)
                            ->endBlock();
                    } else {
                        $this->startBlock('normal', $key);
                    }
                    break;

                // hr
                //3个-- 或者 *** 代表一条横线
                case preg_match("/^[-\*]{3,}\s*$/", $line):
                    $this->startBlock('hr', $key)
                        ->endBlock();
                    break;

                // normal
                default:
                    if ($this->isBlock('list')) {
                        //list下面一 空行仍然属于list
                        if (preg_match("/^(\s*)/", $line)) { // empty line
                            if ($emptyCount > 0) {
                                $this->startBlock('normal', $key);
                            } else {
                                $this->setBlock($key);
                            }

                            $emptyCount ++;
                        } else if ($emptyCount == 0) {
                            $this->setBlock($key);
                        } else {
                            $this->startBlock('normal', $key);
                        }
                    } else if ($this->isBlock('footnote')) {
                        preg_match("/^(\s*)/", $line, $matches);
                        if (strlen($matches[1]) >= $block[3][0]) {
                            $this->setBlock($key);
                        } else {
                            $this->startBlock('normal', $key);
                        }
                    } else if ($this->isBlock('table')) {
                        if (false !== strpos($line, '|')) {
                            $block[3][2] ++;
                            $this->setBlock($key, $block[3]);
                        } else {
                            $this->startBlock('normal', $key);
                        }
                    } else if ($this->isBlock('pre')) {
                        if (preg_match("/^\s*$/", $line)) {
                            if ($emptyCount > 0) {
                                $this->startBlock('normal', $key);
                            } else {
                                $this->setBlock($key);
                            }

                            $emptyCount ++;
                        } else {
                            $this->startBlock('normal', $key);
                        }
                    } else if ($this->isBlock('quote')) {
                        if (preg_match("/^(\s*)/", $line)) { // empty line
                            if ($emptyCount > 0) {
                                $this->startBlock('normal', $key);
                            } else {
                                $this->setBlock($key);
                            }

                            $emptyCount ++;
                        } else if ($emptyCount == 0) {
                            $this->setBlock($key);
                        } else {
                            $this->startBlock('normal', $key);
                        }
                    } else {
                        if (empty($block) || $block[0] != 'normal') {
                            $this->startBlock('normal', $key);
                        } else {
                            $this->setBlock($key);
                        }
                    }
                    break;
            }
        }

        //$this->show($this->_blocks,2);
        return $this->optimizeBlocks($this->_blocks, $lines);
    }

    /**
     * @param array $blocks
     * @param array $lines
     * @return array
     */
    private function optimizeBlocks(array $blocks, array $lines)   //其他地方应该有隐式调用
    {
        $blocks = $this->call('beforeOptimizeBlocks', $blocks, $lines);

        //$this->show($blocks,2);

        $key = 0;
        while (isset($blocks[$key])) {
            $moved = false;

            $block = &$blocks[$key];

            $prevBlock = isset($blocks[$key - 1]) ? $blocks[$key - 1] : NULL;
            $nextBlock = isset($blocks[$key + 1]) ? $blocks[$key + 1] : NULL;

            //$type=$blocks[key][0],$from=$blocks[key][1] $to=$blocks[key][2]
            list ($type, $from, $to) = $block;


            if ('pre' == $type) {            //有一行是以4个空格开头，但是后面仍然为空，当前块也会被设置为pre,如果然后其他行也全是空，就把设置为normal块
               // $this->show($lines,2);
                $isEmpty = array_reduce($lines, function ($result, $line) {
                    return preg_match("/^\s*$/", $line) && $result;
                }, true);            //第三个参数为设置数据组的第一个值，即$resul的初始值为true

                if ($isEmpty) {
                    $block[0] = $type = 'normal';
                }
            }

            if ('normal' == $type) {
                // combine two blocks
                $types = array('list', 'quote');
              /*  echo '1'.'<br/>';
                $this->show($blocks,2);
                echo '1'.'<br/>';*/
                if ($from == $to && preg_match("/^\s*$/", $lines[$from]) //处理特殊情况，如果当前为normal,并且此行为空格，且前后都为list或者引用，则之前分为了3部分，此时合并到第一部分，并删掉后两块，总之就是合并前后两部分之前的空格
                    && !empty($prevBlock) && !empty($nextBlock)) {
                    if ($prevBlock[0] == $nextBlock[0] && in_array($prevBlock[0], $types)) {
                        // combine 3 blocks
                        $blocks[$key - 1] = array(
                            $prevBlock[0],  $prevBlock[1],  $nextBlock[2],  NULL
                        );

                        array_splice($blocks, $key, 2);  //删除从$key开始的2个元素

                        // do not move
                        $moved = true;
                    }
                }
                /*echo '2'.'<br/>';
                //$this->show($blocks,2);
                echo '2'.'<br/>';*/
            }

            if (!$moved) {
                $key ++;
            }
        }
        return $this->call('afterOptimizeBlocks', $blocks, $lines);
    }

    /**
     * parseCode
     *
     * @param array $lines
     * @param array $parts
     * @return string
     */
    private function parseCode(array $lines, array $parts)
    {
        list ($blank, $lang) = $parts;
        $lang = trim($lang);
        $count = strlen($blank);

        if (! preg_match("/^[_a-z0-9-\+\#\:\.]+$/i", $lang)) {
            $lang = NULL;
        } else {
            $parts = explode(':', $lang);
            if (count($parts) > 1) {
                list ($lang, $rel) = $parts;
                $lang = trim($lang);
                $rel = trim($rel);
            }
        }

        $lines = array_map(function ($line) use ($count) {
            return preg_replace("/^[ ]{{$count}}/", '', $line);
        }, array_slice($lines, 1, -1));
        $str = implode("\n", $lines);

        return preg_match("/^\s*$/", $str) ? '' :
            '<pre><code' . (!empty($lang) ? " class=\"{$lang}\"" : '')
            . (!empty($rel) ? " rel=\"{$rel}\"" : '') . '>'
            . htmlspecialchars($str) . '</code></pre>';
    }

    /**
     * parsePre
     *
     * @param array $lines
     * @return string
     */
    private function parsePre(array $lines)
    {
        foreach ($lines as &$line) {
            $line = htmlspecialchars(substr($line, 4));
        }
        $str = implode("\n", $lines);

        return preg_match("/^\s*$/", $str) ? '' : '<pre><code>' . $str . '</code></pre>';
    }

    /**
     * parseSh
     *
     * @param array $lines
     * @param int $num
     * @return string
     */
    private function parseSh(array $lines, $num)
    {
        $line = $this->parseInline(trim($lines[0], '# '));
        return preg_match("/^\s*$/", $line) ? '' : "<h{$num}>{$line}</h{$num}>";
    }

    /**
     * parseMh
     *
     * @param array $lines
     * @param int $num
     * @return string
     */
    private function parseMh(array $lines, $num)
    {
        return $this->parseSh($lines, $num);
    }

    /**
     * parseQuote
     *
     * @param array $lines
     * @return string
     */
    private function parseQuote(array $lines)
    {
        foreach ($lines as &$line) {
            $line = preg_replace("/^\s*> ?/", '', $line);
        }
        $str = implode("\n", $lines);

        return preg_match("/^\s*$/", $str) ? '' : '<blockquote>' . $this->parse($str) . '</blockquote>';
    }

    /**
     * parseList
     *
     * @param array $lines
     * @return string
     */
    private function parseList(array $lines)
    {
        $html = '';
        $minSpace = 99999;
        $rows = array();

        // count levels
        foreach ($lines as $key => $line) {
            if (preg_match("/^(\s*)((?:[0-9a-z]+\.?)|\-|\+|\*)(\s+)(.*)$/", $line, $matches)) {
                $space = strlen($matches[1]);
                $type = false !== strpos('+-*', $matches[2]) ? 'ul' : 'ol';
                $minSpace = min($space, $minSpace);

                $rows[] = array($space, $type, $line, $matches[4]);
            } else {
                $rows[] = $line;
            }
        }

        $found = false;
        $secondMinSpace = 99999;
        foreach ($rows as $row) {
            if (is_array($row) && $row[0] != $minSpace) {
                $secondMinSpace = min($secondMinSpace, $row[0]);
                $found = true;
            }
        }
        $secondMinSpace = $found ? $secondMinSpace : $minSpace;

        $lastType = '';
        $leftLines = array();

        foreach ($rows as $row) {
            if (is_array($row)) {
                list ($space, $type, $line, $text) = $row;

                if ($space != $minSpace) {
                    $leftLines[] = preg_replace("/^\s{" . $secondMinSpace . "}/", '', $line);
                } else {
                    if (!empty($leftLines)) {
                        $html .= "<li>" . $this->parse(implode("\n", $leftLines)) . "</li>";
                    }

                    if ($lastType != $type) {
                        if (!empty($lastType)) {
                            $html .= "</{$lastType}>";
                        }

                        $html .= "<{$type}>";
                    }

                    $leftLines = array($text);
                    $lastType = $type;
                }
            } else {
                $leftLines[] = preg_replace("/^\s{" . $secondMinSpace . "}/", '', $row);
            }
        }

        if (!empty($leftLines)) {
            $html .= "<li>" . $this->parse(implode("\n", $leftLines)) . "</li></{$lastType}>";
        }

        return $html;
    }

    /**
     * @param array $lines
     * @param array $value
     * @return string
     */
    private function parseTable(array $lines, array $value)
    {
        list ($ignores, $aligns) = $value;
        $head = count($ignores) > 0 && array_sum($ignores) > 0;

        $html = '<table>';
        $body = $head ? NULL : true;
        $output = false;

        foreach ($lines as $key => $line) {
            if (in_array($key, $ignores)) {
                if ($head && $output) {
                    $head = false;
                    $body = true;
                }
                continue;
            }

            $line = trim($line);
            $output = true;

            if ($line[0] == '|') {
                $line = substr($line, 1);

                if ($line[strlen($line) - 1] == '|') {
                    $line = substr($line, 0, -1);
                }
            }


            $rows = array_map(function ($row) {
                if (preg_match("/^\s+$/", $row)) {
                    return ' ';
                } else {
                    return trim($row);
                }
            }, explode('|', $line));
            $columns = array();
            $last = -1;

            foreach ($rows as $row) {
                if (strlen($row) > 0) {
                    $last ++;
                    $columns[$last] = array(
                        isset($columns[$last]) ? $columns[$last][0] + 1 : 1,  $row
                    );
                } else if (isset($columns[$last])) {
                    $columns[$last][0] ++;
                } else {
                    $columns[0] = array(1, $row);
                }
            }

            if ($head) {
                $html .= '<thead>';
            } else if ($body) {
                $html .= '<tbody>';
            }

            $html .= '<tr>';

            foreach ($columns as $key => $column) {
                list ($num, $text) = $column;
                $tag = $head ? 'th' : 'td';

                $html .= "<{$tag}";
                if ($num > 1) {
                    $html .= " colspan=\"{$num}\"";
                }

                if (isset($aligns[$key]) && $aligns[$key] != 'none') {
                    $html .= " align=\"{$aligns[$key]}\"";
                }

                $html .= '>' . $this->parseInline($text) . "</{$tag}>";
            }

            $html .= '</tr>';

            if ($head) {
                $html .= '</thead>';
            } else if ($body) {
                $body = false;
            }
        }

        if ($body !== NULL) {
            $html .= '</tbody>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * parseHr
     *
     * @return string
     */
    private function parseHr()
    {
        return '<hr>';
    }

    /**
     * parseNormal
     *
     * @param array $lines
     * @return string
     */
    private function parseNormal(array $lines)
    {
        //$this->show($lines,2);
        foreach ($lines as &$line) {
            $line = $this->parseInline($line);
        }

        $str = trim(implode("\n", $lines));
        $str = preg_replace("/(\n\s*){2,}/", "</p><p>", $str);
        $str = preg_replace("/\n/", "<br>", $str);

        return preg_match("/^\s*$/", $str) ? '' : "<p>{$str}</p>";
    }

    /**
     * parseFootnote
     *
     * @param array $lines
     * @param array $value
     * @return string
     */
    private function parseFootnote(array $lines, array $value)
    {
        list($space, $note) = $value;
        $index = array_search($note, $this->_footnotes);

        if (false !== $index) {
            $lines[0] = preg_replace("/^\[\^((?:[^\]]|\\]|\\[)+?)\]:/", '', $lines[0]);
            $this->_footnotes[$index] = $lines;
        }

        return '';
    }

    /**
     * parseDefine
     *
     * @return string
     */
    private function parseDefinition()
    {
        return '';
    }

    /**
     * parseHtml
     *
     * @param array $lines
     * @param string $type
     * @return string
     */
    private function parseHtml(array $lines, $type)
    {
        foreach ($lines as &$line) {
            $line = $this->parseInline($line,
                isset($this->_specialWhiteList[$type]) ? $this->_specialWhiteList[$type] : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param $url
     * @return string
     */
    public function cleanUrl($url)
    {
    	//x80-xff匹配GBK编码的汉字和标点符号
        if (preg_match("/^\s*((http|https|ftp|mailto):[x80-xff_a-z0-9-\.\/%#@\?\+=~\|\,&\(\)]+)/i", $url, $matches)) {
            return $matches[1];
        } else if (preg_match("/^\s*([x80-xff_a-z0-9-\.\/%#@\?\+=~\|\,&]+)/i", $url, $matches)) {
            return $matches[1];
        } else {
            return '#';
        }
    }

    /**
     * @param $str
     * @return mixed
     */
    public function escapeBracket($str)
    {
        return str_replace(
            array('\[', '\]', '\(', '\)'),  array('[', ']', '(', ')'),  $str
        );
    }

    /**
     * startBlock
     *
     * @param mixed $type
     * @param mixed $start
     * @param mixed $value
     * @return $this
     */
    private function startBlock($type, $start, $value = NULL)
    {
        $this->_pos ++;
        $this->_current = $type;

        $this->_blocks[$this->_pos] = array($type, $start, $start, $value);

        return $this;
    }

    /**
     * endBlock
     *
     * @return $this
     */
    private function endBlock()
    {
        $this->_current = 'normal';
        return $this;
    }

    /**
     * isBlock
     *
     * @param mixed $type
     * @param mixed $value
     * @return bool
     */
    private function isBlock($type, $value = NULL)
    {
        return $this->_current == $type
            && (NULL === $value ? true : $this->_blocks[$this->_pos][3] == $value);
    }

    /**
     * getBlock
     *
     * @return array
     */
    private function getBlock()
    {
        return isset($this->_blocks[$this->_pos]) ? $this->_blocks[$this->_pos] : NULL;
    }

    /**
     * setBlock
     *
     * @param mixed $to
     * @param mixed $value
     * @return $this
     */
    private function setBlock($to = NULL, $value = NULL)
    {
        if (NULL !== $to) {
            $this->_blocks[$this->_pos][2] = $to;
        }

        if (NULL !== $value) {
            $this->_blocks[$this->_pos][3] = $value;
        }

        return $this;
    }

    /**
     * backBlock
     *
     * @param mixed $step
     * @param mixed $type
     * @param mixed $value
     * @return $this
     */
    private function backBlock($step, $type, $value = NULL)
    {
        if ($this->_pos < 0) {
            return $this->startBlock($type, 0, $value);
        }

        $last = $this->_blocks[$this->_pos][2];
        $this->_blocks[$this->_pos][2] = $last - $step;

        if ($this->_blocks[$this->_pos][1] <= $this->_blocks[$this->_pos][2]) {
            $this->_pos ++;
        }

        $this->_current = $type;
        $this->_blocks[$this->_pos] = array(
            $type,  $last - $step + 1,  $last,  $value
        );

        return $this;
    }

    /**
     * @return $this
     */
    private function combineBlock()
    {
        if ($this->_pos < 1) {
            return $this;
        }

        $prev = $this->_blocks[$this->_pos - 1];
        $current = $this->_blocks[$this->_pos];

        $prev[2] = $current[2];
        $this->_blocks[$this->_pos - 1] = $prev;
        $this->_current = $prev[0];
        unset($this->_blocks[$this->_pos]);
        $this->_pos --;

        return $this;
    }
}
