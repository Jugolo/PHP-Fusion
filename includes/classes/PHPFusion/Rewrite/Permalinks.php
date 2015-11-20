<?php
/*-------------------------------------------------------+
| PHP-Fusion Content Management System
| Copyright (C) 2002 - 2011 PHP-Fusion Inc
| https://www.php-fusion.co.uk/
+--------------------------------------------------------+
| Filename: Permalinks.php
| Author: Ankur Thakur
| Co-Author: Takács Ákos (Rimelek)
| Co-Author: Frederick MC Chan (Hien)
+--------------------------------------------------------+
| This program is released as free software under the
| Affero GPL license. You can redistribute it and/or
| modify it under the terms of this license which you
| can read by viewing the included agpl.txt or online
| at www.gnu.org/licenses/agpl.html. Removal of this
| copyright header is strictly prohibited without
| written permission from the original author(s).
+--------------------------------------------------------*/
namespace PHPFusion\Rewrite;

class Permalinks extends RewriteDriver {

    public $debug_regex = false;

    /**
     * Returns the Output
     * This function will first call the handleOutput() and then it will return the
     * modified Output for SEO.
     * @param string $ouput The Output
     * @access public
     */
    public function getOutput($output) {
        global $locale;
        $output = html_entity_decode($output, ENT_QUOTES, $locale['charset']);
        $output = str_replace("\"", "'", $output);
        $this->handleOutput($output);
        return $this->output;
    }

    /**
     * Main Function : Handles the Output
     * This function will Handle the output by calling several functions
     * which are used in this Class.
     * @param string $output The Output from the Fusion
     * @access private
     */
    private function handleOutput($ob_get_contents_from_footer_dot_php) {
        $settings     = \fusion_get_settings();
        $this->output = str_replace("&", "&amp;", $ob_get_contents_from_footer_dot_php);

        // Import the required Handlers
        $this->importHandlers();

        // Include the Rewrites
        $this->includeRewrite();

        // Read from DB
        $this->verifyHandlers();

        // Include the files
        $this->includeHandlers();

        // Prepare the strings
        $this->importPatterns();

        // Make Regex Patterns for the URL Patterns
        $this->prepareSourceRegex();

        // Read output once, sort them all
        $this->prepareStatements();

        // Output and Redirect 301 if NON-SEO url found
        $this->replace_output();

        // Prepend all the File/Images/CSS/JS etc Links with ROOT path
        $this->appendRootAll();

        // For Developer, to see what is happening behind
        if ($settings['debug_seo'] == "1") {
            $this->showQueries();
        }
    }

    /**
     * Import the Available Patterns from Database
     *
     * This will Import all the available Patterns for the Handlers
     * from the Database and put it into $pattern_search and
     * $pattern_replace array.
     *
     * @access private
     */
    protected function importPatterns() {
        if (!empty($this->handlers)) {
            $types = array();
            foreach ($this->handlers as $key => $value) {
                $types[] = "'".$value."'"; // When working on string, the values should be inside single quotes.
            }

            $types_str       = implode(",", $types);
            $query           = "SELECT r.rewrite_name, p.pattern_type, p.pattern_source, p.pattern_target, p.pattern_cat FROM ".DB_PERMALINK_METHOD." p INNER JOIN ".DB_PERMALINK_REWRITE." r WHERE r.rewrite_id=p.pattern_type AND r.rewrite_name IN(".$types_str.") ORDER BY p.pattern_type";
            $this->queries[] = $query;
            $result          = dbquery($query);

            if (dbrows($result)>0) {
                while ($data = dbarray($result)) {
                    if ($data['pattern_cat'] == "normal") {
                        $this->pattern_search[$data['rewrite_name']][]  = $data['pattern_target'];
                        $this->pattern_replace[$data['rewrite_name']][] = $data['pattern_source'];
                    } elseif ($data['pattern_cat'] == "alias") {
                        $this->alias_pattern[$data['rewrite_name']][$data['pattern_source']] = $data['pattern_target'];
                    }
                }
            }

        }
    }

    /**
     * Builds the Regex pattern for a specific Type string
     *
     * This function will build the Regex pattern for a specific string, which is
     * passed to the function.
     *
     * @param string $pattern The String
     * @param string $type    Type or Handler name
     * @access private
     */
    public function prepareSourceRegex() {
        if (is_array($this->pattern_search)) {
            foreach ($this->pattern_search as $type => $RawSearchPatterns) {
                if (!empty($RawSearchPatterns) && is_array($RawSearchPatterns)) {
                    foreach ($RawSearchPatterns as $key => $val) {
                        $regex = $val;
                        // need a tone.
                        $regex = $this->appendSearchPath($regex);
                        $regex = self::cleanRegex($regex);
                        // Rewrite Code is driver file $regex key
                        // Rewrite Replace is driver file $regex values
                        if (isset($this->rewrite_code[$type]) && isset($this->rewrite_replace[$type])) {
                            $regex = str_replace($this->rewrite_code[$type], $this->rewrite_replace[$type], $regex);
                        }
                        $regex                             = $this->wrapQuotes($regex);
                        $this->patterns_regex[$type][$key] = "~".$regex."~i";
                    }
                }
            }
        }
    }

    private function do_output_lookup($searchVars, $field, $key) {
        if (preg_match($searchVars, $this->output)) {
            // Store all the matches into the $matches array
            preg_match_all($searchVars, $this->output, $matches); // 1 search string against output.
            // Returns the Tag from the Unique DBID by which the Pattern in recognized, i.e, %news_id%, %thread_id%
            $tag = $this->getUniqueIDtag($field);
            $attr = $this->getUniqueIDfield($field);
            $pos = $this->getTagPosition($this->pattern_search[$field][$key], $attr);
            if ($pos != 0) {
                $found_matches = array_unique($matches[$pos]); // This is to remove duplicate matches
                foreach ($found_matches as $match_key => $match) {
                    $replace = $this->pattern_replace[$field][$key]; // replace pattern
                    // Replacing each Tag with its Database Value if any
                    // Example: %thread_title% should be replaced with thread_subject
                    if (isset($this->dbinfo[$field])) {
                        // replace ids.
                        foreach ($this->dbinfo[$field] as $other_tags => $other_attr) {
                            if (strstr($replace, $other_tags)) {
                                $replace = str_replace($other_tags,
                                                       $this->data_cache[$field][$match][$other_attr],
                                                       $replace);
                            }
                        }
                    }
                    $search = str_replace($tag, $match, $this->pattern_search[$field][$key]);
                    // this might be the culprit in not making the navigation other tag work and replication.
                    $search = $this->replaceOtherTags($field, $this->pattern_replace[$field][$key], $search, $matches, $match_key); // BUG This will stop &amp; parsing ! Added: Replace Tags values in Search Pattern Also
                    $search = $this->makeSearchRegex($this->appendSearchPath($search), $field);
                    // Replacing each of the Tag with its suitable match found on the Page - Suitable becomes non-suitable if you put Pagenav inside a DBID Type.
                    // Every page nav becomes identical!
                    $replace = $this->replaceOtherTags($field, $this->pattern_search[$field][$key], $replace, $matches, $match_key);
                    $replace = self::cleanURL($replace);
                    $replace = $this->wrapQuotes($replace);
                    unset($found_matches);
                    $this->regex_statements['pattern'][$field][] = array($search => $replace);
                }
            } else {
                // outright replacement
                $replace = $this->wrapQuotes($this->pattern_replace[$field][$key]); // replace pattern
                $this->regex_statements['pattern'][$field][] = array($searchVars => $replace);
                //$this->regex_statements['failed_pattern'][$field][] = array("search" => $searchVars, "status"=>"Found but not included in Permalink logic");
            }
        } else {
            $this->regex_statements['failed_pattern'][$field][] = array("search" => $searchVars, "status"=>"Failed either - failed regex expression in driver or failed to find matching content");
        }
    }

    // Add to redirect search
    private function do_uri_lookup($field, $key) {

        if (isset($this->pattern_replace[$field][$key]) && $this->pattern_search[$field][$key]) {
            $searchVars = $this->pattern_search[$field][$key];
            $replace = $this->pattern_replace[$field][$key]; // need the one with &amp;
            if (isset($this->rewrite_code[$field]) && isset($this->rewrite_replace[$field])) {
                $searchVars = str_replace($this->rewrite_code[$field], $this->rewrite_replace[$field], $searchVars);
            }
            $searchVars = "~".self::cleanRegex($searchVars)."$~i";
            if (preg_match($searchVars, PERMALINK_CURRENT_PATH, $matches)) {
                // Store all the matches into the $matches array
                preg_match_all($searchVars, PERMALINK_CURRENT_PATH, $matches); // 1 search string against output.
                // Returns the Tag from the Unique DBID by which the Pattern in recognized, i.e, %news_id%, %thread_id%
                $tag = $this->getUniqueIDtag($field);
                $attr = $this->getUniqueIDfield($field);
                $pos = $this->getTagPosition($this->pattern_search[$field][$key], $attr);
                if ($pos != 0) {
                    $found_matches = array_unique($matches[$pos]); // This is to remove duplicate matches
                    foreach ($found_matches as $match_key => $match) {
                        $replace = $this->pattern_replace[$field][$key]; // replace pattern
                        // Replacing each Tag with its Database Value if any
                        // Example: %thread_title% should be replaced with thread_subject
                        if (isset($this->dbinfo[$field])) {
                            // replace ids.
                            foreach ($this->dbinfo[$field] as $other_tags => $other_attr) {
                                if (strstr($replace, $other_tags)) {
                                    $replace = str_replace($other_tags,
                                                           $this->data_cache[$field][$match][$other_attr],
                                                           $replace);
                                }
                            }
                        }
                        $search = str_replace($tag, $match, $this->pattern_search[$field][$key]);
                        // this might be the culprit in not making the navigation other tag work and replication.
                        $search = $this->replaceOtherTags($field, $this->pattern_replace[$field][$key], $search, $matches, $match_key); // BUG This will stop &amp; parsing ! Added: Replace Tags values in Search Pattern Also
                        // Replacing each of the Tag with its suitable match found on the Page - Suitable becomes non-suitable if you put Pagenav inside a DBID Type.
                        // Every page nav becomes identical!
                        $replace = $this->replaceOtherTags($field, $this->pattern_search[$field][$key], $replace, $matches, $match_key);
                        $replace = self::cleanURL($replace);
                        $this->redirect_301($replace);
                        //unset($found_matches);
                        //$this->regex_statements['pattern_uri_redirect'][$field][] = array($uri_search => $uri_replace);
                    }
                    // end position 0 (it means these were an arrays)
                } else {
                    $replace = self::cleanURL($replace);
                    $this->redirect_301($replace);
                    //$this->regex_statements['pattern_uri_redirect'][$field][] = array($searchVars => $replace);
                }
            }
        }
    }

    /**
     * Builds the Regular Expressions Patterns
     *
     * This function will create the Regex patterns and will put the built patterns
     * in $patterns_regex array. This array will then used in preg_match function
     * to match against current request.
     *
     * @access private
     */
    private function prepareStatements() {

        // Patterns
        self::getOtherTags();

        foreach($this->patterns_regex as $field => $searchRegex) { // rawsearchpatterns
            foreach($searchRegex as $key => $searchVars) { // searchvariables
                if (isset($this->dbid[$field])) {
                    $this->do_output_lookup($searchVars, $field, $key);
                } else {
                    $this->regex_statements['failed_pattern'][$field][] = array("status"=>"This ".$field." has been omitted entirely and short of development.");
                }
                $this->do_uri_lookup($field, $key);
            }
        }

        // Alias -- this is only activated after RC - Need new admin panel
        if (!empty($this->handlers)) {
            $fields = array();
            foreach ($this->handlers as $key => $value) {
                $fields[] = "'".$value."'";
            }
            $handlers = implode(",", $fields);
            $query = "SELECT * FROM ".DB_PERMALINK_ALIAS." WHERE alias_type IN(".$handlers.")";
            $this->queries[] = $query;
            $aliases = dbquery($query);
            if (dbrows($aliases)) {
                while ($alias = dbarray($aliases)) {
                    //$this->replaceAliasPatterns($data);
                    $alias_php_url = $this->getAliasURL($alias['alias_url'], $alias['alias_php_url'], $alias['alias_type']);
                    $field = $alias['alias_type'];

                    if (array_key_exists(1, $alias_php_url) && strcmp(PERMALINK_CURRENT_PATH, $alias_php_url[1]) == 0) {
                        $this->redirect_301($alias_php_url[0]);
                    }

                    // Check If there are any Alias Patterns defined for this Type or not
                    if (array_key_exists($field, $this->alias_pattern)) {

                        foreach ($this->alias_pattern[$field] as $replace => $search) {
                            // Secondly, Replace %alias_target% with Alias PHP URL
                            $search = str_replace("%alias_target%", $alias['alias_php_url'], $search);
                            $search_string = $search;

                            $alias_search = str_replace($this->rewrite_code[$field], $this->rewrite_replace[$field], $search_string);
                            $alias_search = self::cleanRegex($alias_search);
                            $alias_search = "~^".$alias_search."$";

                            // Now Replace Pattern Tags with suitable Regex Codes
                            $search = $this->makeSearchRegex($search, $field);

                            // If the Pattern is found in the Output
                            if (preg_match($search, $this->output)) {
                                // Search them all and put them in $matches
                                preg_match_all($search, $this->output, $matches);
                                // $matches[0] represents the Array of all the matches for this Pattern
                                foreach ($matches[0] as $count => $match) {
                                    // First of all, Replace %alias% with the actual Alias Name
                                    $replace_str = str_replace("%alias%", $alias['alias_url'], $replace);
                                    $match = self::cleanRegex($match);
                                    // Replace Tags with their suitable matches
                                    $replace_str = $this->replaceOtherTags($field, $search_string, $replace_str, $matches, $count);
                                    $replace_str = $this->cleanURL($replace_str);

                                    $this->regex_statements['alias'][$field][] = array($match => $replace_str);
                                    $this->regex_statements['alias_redirect'][$field][] = array($alias_search => $replace_str);
                                }
                            } else {
                                $this->regex_statements['failed_alias'][$field][] = array("search" => $search, "status"=>"failed");
                            }
                        }
                    }
                    $this->aliases[] = $alias;
                }
            }
        }
        if ($this->debug_regex == true) print_p($this->regex_statements);
    }


    /**
     * Do full replacement of the HTML output
     */
    private function replace_output() {
        // Pattern translation
        if (!empty($this->regex_statements['pattern'])) {
            foreach ($this->regex_statements['pattern'] as $handler => $rules) {
                $_patterns = flatten_array($rules);
                foreach ($_patterns as $search => $replace) {
                    $this->output = preg_replace($search, $replace, $this->output);
                }
            }
        }
        // Alias translation
        if (!empty($this->regex_statements['alias'])) {
            foreach ($this->regex_statements['alias'] as $handler => $rules) {
                $_patterns = flatten_array($rules);
                foreach ($_patterns as $search => $replace) {
                    $this->output = preg_replace($search, $replace, $this->output);
                }
            }
        }

        // Alias Redirecting
        if (!empty($this->regex_statements['alias_redirect'])) {
            foreach ($this->regex_statements['alias_redirect'] as $handler => $rules) {
                $_patterns = flatten_array($rules);
                foreach ($_patterns as $search => $replace) {
                    if (preg_match($search, PERMALINK_CURRENT_PATH, $matches)) {
                        $this->redirect_301($replace);
                    }
                }
            }
        }
    }

    /**
     * Get Alias URL for Permalink
     *
     * This function will return an Array of 2 elements for a specific Alias:
     * 1. The Permalink URL of Alias
     * 2. PHP URL of the Alias
     *
     * @param string $url     The Permalink URL (incomplete)
     * @param string $php_url The PHP URL (incomplete)
     * @param string $type    Type of Alias
     * @access private
     */
    private function getAliasURL($url, $php_url, $type) {
        $return_url = array();
        // 1 => $search, 2 => $replace
        if (isset($this->alias_pattern[$type]) && is_array($this->alias_pattern[$type])) {
            foreach ($this->alias_pattern[$type] as $search => $replace) {
                $search  = str_replace("%alias%", $url, $search);
                $replace = str_replace("%alias_target%", $php_url, $replace);
                if ($replace == PERMALINK_CURRENT_PATH) {
                    $return_url[] = $search;
                    $return_url[] = $replace;
                }
            }
        }
        return $return_url;
    }

    /**
     * Append File Root
     *
     * Append the ROOT Dir Path to all relative links, which are from website
     * This function will append the root directory path for all links, which
     * are in website. (Not External HTTP links)
     */
    protected function appendRootAll() {
        if (preg_match("/(href|src)='((?!(htt|ft)p(s)?:\/\/)[^\']*)'/i", $this->output)) {
            $basedir = str_replace(array(".", "/"), array("\.", "\/"), BASEDIR);
            $basedir = preg_replace("/(href|src)='(".$basedir.")*([^\':]*)'/i", "$1='".ROOT."$3'", $this->output);
            // Remove ../ before http://
            $loop = 7;
            for ($i = 1; $i <= $loop; $i++) {
                $basedir = str_replace(str_repeat('../', $i).'http://', 'http://', $basedir);
            }
            // Remove ../ before https://
            for ($i = 1; $i <= $loop; $i++) {
                $basedir = str_replace(str_repeat('../', $i).'https://', 'https://', $basedir);
            }
            $this->output = $basedir;
        }
    }

    /**
     * Debug Function for Developers
     * Just a simple function for the developer to see, what is going in the background.
     * @access private
     */
    private function showQueries() {
        if (!empty($this->queries)) {
            if (is_array($this->queries)) {
                ob_start();
                echo "\n<div class='permalinks-queries' style='padding: 10px 10px 10px 10px; border: 3px double #225500; background-color: #ccffaa; line-height: 15px;'>\n";
                echo "<strong>Queries which were made for Permalinks:</strong><br /><br />\n";
                foreach ($this->queries as $key => $query) {
                    echo $query.";<br />\n";
                }
                echo "<script type='text/javascript'>\n"
                    ."function toggledebugdiv() {\n"
                    ."	$('#permalink-debug-info').slideToggle('slow');\n"
                    ."}\n"
                    ."</script>\n";
                echo "<input type='button' value='Toggle Permalinks Debug Information' onclick='toggledebugdiv()' />\n";
                echo "<div id='permalink-debug-info' style='display: none;'>\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Handlers Stack = Array (<br />";
                foreach ($this->handlers as $key => $name) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$name."<br />";
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Alias Patterns = Array (<br />";
                foreach ($this->alias_pattern as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Rewrite Codes = Array (<br />";
                foreach ($this->rewrite_code as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Rewrite Replace = Array (<br />";
                foreach ($this->rewrite_replace as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Pattern Search = Array (<br />";
                foreach ($this->pattern_search as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Pattern Replace = Array (<br />";
                foreach ($this->pattern_replace as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Pattern Regex = Array (<br />";
                foreach ($this->patterns_regex as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "DB Names = Array (<br />";
                foreach ($this->dbname as $type => $val) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => ".$val."<br />";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "DB ID = Array (<br />";
                foreach ($this->dbid as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "DB Info = Array (<br />";
                foreach ($this->dbinfo as $type => $tag) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($tag as $key => $val) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$key."] => ".$val."<br />";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "ID Cache = Array (<br />";
                foreach ($this->id_cache as $type => $info) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($info as $id => $dbinfo) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$id."] => Array (<br />";
                        foreach ($dbinfo as $colname => $colvalue) {
                            echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$colname."] => ".$colvalue."<br />";
                        }
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "<hr style='border-color:#000;' />\n";
                echo "Data Cache = Array (<br />";
                foreach ($this->data_cache as $type => $info) {
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;[".$type."] => Array (<br />";
                    foreach ($info as $id => $dbinfo) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$id."] => Array (<br />";
                        foreach ($dbinfo as $colname => $colvalue) {
                            echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;[".$colname."] => ".$colvalue."<br />";
                        }
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                    }
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;)<br />\n";
                }
                echo ");<br />\n";
                echo "</div>\n";
                echo "</div>\n";
                $queries_output = ob_get_contents();
                if (ob_get_length() !== FALSE) {
                    ob_end_clean();
                }
                $this->output = preg_replace("#<body>#", "<body>".$queries_output, $this->output);
            }
        }
    }
}