<?php class full_text_grabber extends Plugin
{
    private $host;
    
    function about()
    {
        return array(
            1.0,
            "Try to get full-text version of the article using (self-hosted) Full-Text RSS or Mercury Parser API",
            "https://github.com/SquirrelMe/ttrss-fulltext/"
        );
    }
    function flags()
    {
        return array(
            "needs_curl" => true
        );
    }
    function save()
    {
        $this
            ->host
            ->set($this, "API_address", $_POST["API_address"]);
        
        $this
            ->host
            ->set($this, "API_type", $_POST["API_type_select"]);

        echo __("Your API Endpoint.");
    }
    function init($host)
    {
        $this->host = $host;
        
        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            return;
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        
        $host->add_filter_action($this, "action_inline", __("Inline content"));
    }
    function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") return;

        print "<div dojoType='dijit.layout.AccordionPane' 
            title=\"<i class='material-icons'>extension</i> ".__('Full-text grabber settings')."\">";

        if (version_compare(PHP_VERSION, '5.6.0', '<')){
            print_error("This plugin requires PHP version 5.6.");
        }
        else {
            print "<h2>" . __("Global settings") . "</h2>";

            print_notice("Enable for specific feeds in the feed editor.");

            print "<form dojoType='dijit.form.Form'>";

            print "<script type='dojo/method' event='onSubmit' args='evt'>
                evt.preventDefault();
                if (this.validate()) {
                    console.log(dojo.objectToQuery(this.getValues()));
                    new Ajax.Request('backend.php', {
                        parameters: dojo.objectToQuery(this.getValues()),
                        onComplete: function(transport) {
                            Notify.info(transport.responseText);
                        }
                    });

                    // this.reset();

                }
                </script>";

            print_hidden("op", "pluginhandler");
            print_hidden("method", "save");
            print_hidden("plugin", "full_text_grabber");

            $API_address = $this
                ->host
                ->get($this, "API_address");

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='API_address_box' value='$API_address'/>";
            print "<label for='API_address_box'>" . __(" The (potentially self-hosted) API address (including the port number), eg https://foo.bar.com:1234.") . "</label>";

            $API_type = $this
                ->host
                ->get($this, "API_type");

            if ($API_type == "Full-Text RSS")
            {
                print "<select name='API_type_select' data-dojo-type='dijit/form/Select'>
                    <option value='Full-Text RSS'>Full-Text RSS</option>
                    <option value='Mercury'>Mercury parser</option>
                    </select>";
            }
            elseif ($API_type == "Mercury")
            {
                print "<select name='API_type_select' data-dojo-type='dijit/form/Select'>
                    <option value='Mercury'>Mercury parser</option>
                    <option value='Full-Text RSS'>Full-Text RSS</option>
                    </select>";
            }
            else
            {
                print "<select name='API_type_select' data-dojo-type='dijit/form/Select'>
                    <option value=''>-- SELECT API --</option>
                    <option value='Full-Text RSS'>Full-Text RSS</option>
                    <option value='Mercury'>Mercury parser</option>
                    </select>";
            }

            print "<p>";
            print print_button("submit", __("Save"), "class='alt-primary'");
            print "</form>";

            $enabled_feeds = $this
                ->host
                ->get($this, "enabled_feeds");

            if (!is_array($enabled_feeds)) $enabled_feeds = array();

            $enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);

            $this
                ->host
                ->set($this, "enabled_feeds", $enabled_feeds);

            if (count($enabled_feeds) > 0)
            {
                print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

                print "<ul class='panel panel-scrollable list list-unstyled'>";

                foreach ($enabled_feeds as $f) {
                    print "<li><i class='material-icons'>rss_feed</i> <a href='#'
                        onclick='CommonDialogs.editFeed($f)'>".
                        Feeds::getFeedTitle($f) . "</a></li>";
                }

                print "</ul>";
            }
        }
        print "</div>";
    }

    function hook_prefs_edit_feed($feed_id)
    {
        print "<header>".__("Mercury Fulltext")."</header>";
        print "<section>";
        
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) $enabled_feeds = array();
        
        $key = array_search($feed_id, $enabled_feeds);
        $checked = $key !== false ? "checked" : "";

        print "<fieldset>";
        
        print "<label class='checkbox'><input dojoType='dijit.form.CheckBox' type='checkbox' id='full_text_grabber_enabled' name='full_text_grabber_enabled' $checked>&nbsp;" . __('Get fulltext via API') . "</label>";

        print "</fieldset>";

        print "</section>";
    }

    function hook_prefs_save_feed($feed_id)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) $enabled_feeds = array();
        
        $enable = checkbox_to_sql_bool($_POST["full_text_grabber_enabled"]);
        
        $key = array_search($feed_id, $enabled_feeds);
        
        if ($enable)
        {
            if ($key === false)
            {
                array_push($enabled_feeds, $feed_id);
            }
        }
        else
        {
            if ($key !== false)
            {
                unset($enabled_feeds[$key]);
            }
        }

        $this
            ->host
            ->set($this, "enabled_feeds", $enabled_feeds);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function hook_article_filter_action($article, $action)
    {
        return $this->process_article($article);
    }

    function process_article($article)
    {
        $ch = curl_init();
        
        $url = $article['link'];
        
        $api_address = $this
            ->host
            ->get($this, "API_address");
        
        $api_type = $this
            ->host
            ->get($this, "API_type");
            
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($api_type == "Full-Text RSS")
        {
            curl_setopt($ch, CURLOPT_URL, rtrim($api_address, '/') . '/extract.php?url=' . rawurlencode($url));
        }
        elseif ($api_type == "Mercury")
        {
            curl_setopt($ch, CURLOPT_URL, rtrim($api_address, '/') . '/parser?url=' . rawurlencode($url));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
        
        $output = json_decode(curl_exec($ch));
        
        curl_close($ch);
        
        $extracted_content = $output->content;
        
        if ($extracted_content)
        {
            $article["content"] = $extracted_content;
        }

        return $article;
    }

    function hook_article_filter($article)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) return $article;
        
        $key = array_search($article["feed"]["id"], $enabled_feeds);
        
        if ($key === false) return $article;
        
        return $this->process_article($article);
    }

    function api_version()
    {
        return 2;
    }

    private function filter_unknown_feeds($enabled_feeds)
    {
        $tmp = array();
        
        foreach ($enabled_feeds as $feed)
        {
            $sth = $this
                ->pdo
                ->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
            $sth->execute([$feed, $_SESSION['uid']]);
            
            if ($row = $sth->fetch())
            {
                array_push($tmp, $feed);
            }
        }

        return $tmp;
    }
}

