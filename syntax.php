<?php
/**
 * Bureaucracy Plugin: Allows flexible creation of forms
 *
 * This plugin allows definition of forms in wiki pages. The forms can be
 * submitted via email or used to create new pages from templates.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Adrian Lang <dokuwiki@cosmocode.de>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_bureaucracy extends DokuWiki_Syntax_Plugin {
    // allowed types and the number of arguments
    var $form_id = 0;

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<form>.*?</form>',$mode,'plugin_bureaucracy');
    }


    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match = substr($match,6,-7); // remove form wrap
        $lines = explode("\n",$match);
        $action = array('type' => '',
                        'argv' => array());
        $thanks = '';
        $labels = '';

        // parse the lines into an command/argument array
        $cmds = array();
        while (count($lines) > 0) {
            $line = trim(array_shift($lines));
            if (!$line) continue;
            $args = $this->_parse_line($line, $lines);
            $args[0] = $this->_sanitizeClassName($args[0]);

            if (in_array($args[0], array('action', 'thanks', 'labels'))) {
                if (count($args) < 2) {
                    msg(sprintf($this->getLang('e_missingargs'),hsc($args[0]),hsc($args[1])),-1);
                    continue;
                }

                // is action element?
                if ($args[0] == 'action') {
                    array_shift($args);
                    $action['type'] = array_shift($args);
                    $action['argv'] = $args;
                    continue;
                }

                // is thank you text?
                if ($args[0] == 'thanks') {
                    $thanks = $args[1];
                    continue;
                }

                // is labels?
                if ($args[0] == 'labels') {
                    $labels = $args[1];
                    continue;
                }
            }

            $class = 'syntax_plugin_bureaucracy_field_' . $args[0];
            $cmds[] = new $class($args);
        }

        // check if action is available
        $action['type'] = $this->_sanitizeClassName($action['type']);
        if (!$action['type'] ||
            !@file_exists(DOKU_PLUGIN.'bureaucracy/actions/' .
                          $action['type'] . '.php')) {
            msg(sprintf($this->getLang('e_noaction'), $action), -1);
        }
        // set thank you message
        if (!$thanks) {
            $thanks = $this->getLang($action['type'].'_thanks');
        } else {
            $thanks = hsc($thanks);
        }
        return array('data'=>$cmds,'action'=>$action,'thanks'=>$thanks,'labels'=>$labels);
    }

    /**
     * Create output
     */
    function render($format, &$R, $data) {
        global $ID;
        if ($format != 'xhtml') return false;
        $R->info['cache'] = false; // don't cache

        /**
         * replace some time and name placeholders in the default values
         * @var $opt syntax_plugin_bureaucracy_field */
        foreach ($data['data'] as $id => &$opt) {
            if(isset($opt->opt['value'])) {
                $opt->opt['value'] = $this->replace($opt->opt['value']);
            }

        }

        if($data['labels']) $this->loadlabels($data);

        $this->form_id++;
        if (isset($_POST['bureaucracy']) && checkSecurityToken() &&
            $_POST['bureaucracy']['$$id'] == $this->form_id) {
            $success = $this->_handlepost($data);
            if ($success !== false) {
                $R->doc .= '<div class="bureaucracy__plugin" id="scroll__here">'
                        .  $success . '</div>';
                return true;
            }
        }

        $R->doc .= $this->_htmlform($data['data']);

        return true;
    }

    /**
     * Initializes the labels, loaded from a defined labelpage
     *
     * @param array $data all data passed to render()
     */
    protected function loadlabels(&$data){
        global $INFO;
        $labelpage = $data['labels'];
        $exists = false;
        resolve_pageid($INFO['namespace'], $labelpage, $exists);
        if(!$exists){
            msg(sprintf($this->getLang('e_labelpage'), html_wikilink($labelpage)),-1);
            return;
        }

        // parse simple list (first level cdata only)
        $labels = array();
        $instructions = p_cached_instructions(wikiFN($labelpage));
        $inli = 0;
        $item = '';
        foreach($instructions as $instruction){
            if($instruction[0] == 'listitem_open'){
                $inli++;
                continue;
            }
            if($inli === 1 && $instruction[0] == 'cdata'){
                $item .= $instruction[1][0];
            }
            if($instruction[0] == 'listitem_close'){
                $inli--;
                if($inli === 0 ){
                    list($k, $v) = explode('=', $item, 2);
                    $k = trim($k);
                    $v = trim($v);
                    if($k && $v) $labels[$k] = $v;
                    $item = '';
                }
            }
        }

        // apply labels to all fields
        $len = count($data['data']);
        for($i=0; $i<$len; $i++){
            if(!isset($data['data'][$i]->opt['label'])) continue;
            $label = $data['data'][$i]->opt['label'];

            if(isset($labels[$label])) {
                $data['data'][$i]->opt['display'] = $labels[$label];
            }
        }

        if (isset($data['thanks'])) {
            if (isset($labels[$data['thanks']])) {
                $data['thanks'] = $labels[$data['thanks']];
            }
        }

    }


    /**
     * Validate data, perform action
     */
    function _handlepost($data) {
        $success = true;
        foreach ($data['data'] as $id => $opt) {
            /** @var $opt syntax_plugin_bureaucracy_field */
            $_ret = true;
            if ($opt->getFieldType() === 'fieldset') {
                $params = $opt->handle_post($_POST['bureaucracy'][$id], $id, $data['data']);
                $_ret = $opt->handle_post($params);
            } elseif(!$opt->hidden) {
                $_ret = $opt->handle_post($_POST['bureaucracy'][$id]);
            }
            if (!$_ret) {
                // Do not return instantly to allow validation of all fields.
                $success = false;
            }
        }
        if (!$success) {
            return false;
        }

        $class = 'syntax_plugin_bureaucracy_action_' . $data['action']['type'];
        $action = new $class();

        try {
            $success = $action->run($data['data'], $data['thanks'],
                                    $data['action']['argv']);
        } catch (Exception $e) {
            msg($e->getMessage());
            return false;
        }

        // Perform after_action hooks
        foreach($data['data'] as $id => $field) {
            $field->after_action();
        }
        return $success;
    }

    /**
     * Create the form
     */
    function _htmlform($data){
        global $ID;

        $form = new Doku_Form(array('class' => 'bureaucracy__plugin',
                                    'id'    => 'bureaucracy__plugin' . $this->form_id));
        $form->addHidden('id', $ID);
        $form->addHidden('bureaucracy[$$id]', $this->form_id);

        foreach ($data as $id => $opt) {
            $opt->render(array('name' => 'bureaucracy['.$id.']'), $form);
        }

        return $form->getForm();
    }

    /**
     * Parse a line into (quoted) arguments
     *
     * @author William Fletcher <wfletcher@applestone.co.za>
     */
    function _parse_line($line, &$lines) {
        $args = array();
        $inQuote = false;
        $arg = '';
        do {
            $len = strlen($line);
            for ( $i = 0 ; $i < $len; $i++ ) {
                if ( $line{$i} == '"' ) {
                    if ($inQuote) {
                        array_push($args, $arg);
                        $inQuote = false;
                        $arg = '';
                        continue;
                    } else {
                        $inQuote = true;
                        continue;
                    }
                } else if ( $line{$i} == ' ' ) {
                    if ($inQuote) {
                        $arg .= ' ';
                        continue;
                    } else {
                        if ( strlen($arg) < 1 ) continue;
                        array_push($args, $arg);
                        $arg = '';
                        continue;
                    }
                }
                $arg .= $line{$i};
            }
            if (!$inQuote || count($lines) === 0) break;
            $line = array_shift($lines);
            $arg .= "\n";
        } while (true);
        if ( strlen($arg) > 0 ) array_push($args, $arg);
        return $args;
    }

    function _sanitizeClassName($classname) {
        return preg_replace('/[^\w\x7f-\xff]/', '', strtolower($classname));
    }

    /**
     * Replace some placeholders for userinfo and time
     *   - more replacements are done in syntax_plugin_bureaucracy_action_template
     * @param $input
     * @return mixed
     */
    function replace($input) {
        global $USERINFO;
        global $conf;

        // replace placeholders
        return str_replace(array(
                                '@USER@',
                                '@NAME@',
                                '@MAIL@',
                                '@DATE@',
                                '@YEAR@',
                                '@MONTH@',
                                '@DAY@',
                                '@TIME@'
                           ),
                           array(
                                $_SERVER['REMOTE_USER'],
                                $USERINFO['name'],
                                $USERINFO['mail'],
                                strftime($conf['dformat']),
                                date('Y'),
                                date('m'),
                                date('d'),
                                date('H:i')
                           ), $input);
    }
}
