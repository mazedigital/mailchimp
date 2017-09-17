<?php

if(!defined("__IN_SYMPHONY__")) die("<h2>Error</h2><p>You cannot directly access this file</p>");
include_once(EXTENSIONS . '/mailchimp/vendor/autoload.php');

use \DrewM\MailChimp\MailChimp;
class extension_mailchimp extends Extension{

    public function uninstall()
    {
        Symphony::Configuration()->remove('mailchimp');
        Symphony::Configuration()->write();
    }

    public function getSubscribedDelegates()
    {
        return array(
            array(
                'page'      => '/system/preferences/',
                'delegate'  => 'AddCustomPreferenceFieldsets',
                'callback'  => 'addCustomPreferenceFieldsets'
            ),
            array(
                'page' => '/blueprints/events/edit/',
                'delegate' => 'AppendEventFilter',
                'callback' => 'appendEventFilter'
            ),
            array(
                'page' => '/blueprints/events/new/',
                'delegate' => 'AppendEventFilter',
                'callback' => 'appendEventFilter'
            ),
            array(
                'page' => '/frontend/',
                'delegate' => 'EventFinalSaveFilter',
                'callback' => 'eventFinalSaveFilter'
            ),
        );
    }

    /*-------------------------------------------------------------------------
        Utilities:
    -------------------------------------------------------------------------*/

    public function getKey()
    {
        return Symphony::Configuration()->get('key', 'mailchimp');
    }

    public function getList()
    {
        return Symphony::Configuration()->get('list', 'mailchimp');
    }

    /*-------------------------------------------------------------------------
        Delegates:
    -------------------------------------------------------------------------*/

    public function addCustomPreferenceFieldsets($context)
    {
        $fieldset = new XMLElement('fieldset');
        $fieldset->setAttribute('class', 'settings');
        $fieldset->appendChild(
            new XMLElement('legend', 'Mailchimp')
        );

        $group = new XMLElement('div');
        $group->setAttribute('class', 'group');

        $api = Widget::Label('API Key');
        $api->appendChild(Widget::Input(
            'settings[mailchimp][key]', General::Sanitize($this->getKey())
        ));
        $api->appendChild(
            new XMLElement('p', Widget::Anchor(__('Generate your API Key'), 'http://kb.mailchimp.com/article/where-can-i-find-my-api-key'), array(
                'class' => 'help'
            ))
        );

        $group->appendChild($api);

        $list = Widget::Label('Default List ID');
        $list->appendChild(Widget::Input(
            'settings[mailchimp][list]', General::Sanitize($this->getList())
        ));
        $list->appendChild(
            new XMLElement('p', __('Can be overidden from the frontend'), array(
                'class' => 'help'
            ))
        );
        $group->appendChild($list);

        $fieldset->appendChild($group);

        $context['wrapper']->appendChild($fieldset);
    }

    public function appendEventFilter($context)
    {
        $handle = 'add-to-mailchimp';
        $selected = (in_array($handle, $context['selected']));
        $context['options'][] = Array(
            $handle, $selected, General::sanitize("Add to mailchimp")
        );
    }

    public function eventFinalSaveFilter($context)
    {
        $handle = 'add-to-mailchimp';
        if (in_array($handle, (array) $context['event']->eParamFILTERS)) {
 
        $api = new MailChimp($this->getKey());
        $result = new XMLElement("mailchimp");

        $email = $context["fields"]["email"];
        $lists = $this->getList();

        // For post values
        $fields = $context["fields"];

        $subscribe = $context["fields"]["mailchimp-subscribe"];
        

        // Valid email?
        if(!$email)
        {
            $error = new XMLElement('error', 'E-mail is invalid.');
            $error->setAttribute("handle", 'email');

            $result->appendChild($error);
            $result->setAttribute("result", "error");

            return $result;
        }

        // Valid email?
        if($subscribe != "on")
        {
            $result->appendChild($error);
            $result->setAttribute("result", "optout");

            return $result;
        }

        $explodedLists = explode(',', $lists);

        foreach ($explodedLists as $list) {

            // Default subscribe parameters
            $custom_status = $fields['status'];
            $params = array(
                'email_address' => $email,
                //status = pending enables double opt in. Set to subscribed for no double opt in
                'status' => ($custom_status) ? $custom_status : 'pending'
            );
            
            try {
                if (is_array($fields['merge'])) {
                    $params['merge_fields'] = $fields['merge'];
                }
                
                // check if user already exists
                $hash_email = $api->subscriberHash($email);
                //$check_result = $api->get("lists/$list/members/$hash_email");
                $is_already_subscribed = isset($check_result['id']);
                
                //if status is default value and subscriber already in list, status must not be changed
                if ($is_already_subscribed && !$custom_status && isset($check_result['status'])) {
                    $params['status'] = $check_result['status'];
                }
                
                // Subscribe or update the user
                $api_result = $api->post("lists/$list/members", $params);
               
                if ($is_already_subscribed) {
                    $result->setAttribute("result", "error");

                    $error = new XMLElement("message", "Email address already in list.");
                    $result->appendChild($error);
                    $error = new XMLElement("code", "409");
                    $result->appendChild($error);
                } else if(General::intval($api_result['status']) > -1) {
                    $result->setAttribute("result", "error");

                    // no error message found with merge vars in it
                    if ($error == null) {
                        $msg = General::sanitize($api_result['detail']);
                        $error = new XMLElement("message", strlen($msg) > 0 ? $msg : 'Unknown error', array(
                            'code' => $api_result['code'],
                            'name' => $api_result['name']
                        ));
                    }

                    $result->appendChild($error);
                } else if(isset($_REQUEST['redirect'])) {
                    redirect($_REQUEST['redirect']);
                } else {
                    $result->setAttribute("result", "success");
                    $result->appendChild(
                        new XMLElement('message', __('Subscriber added to list successfully'))
                    );
                }

                // Set the post values
                $post_values = new XMLElement("post-values");
                General::array_to_xml($post_values, $fields);
                $result->appendChild($post_values);
            }
            catch (Exception $ex) {
                $error = new XMLElement('error', General::wrapInCDATA($ex->getMessage()));
                $result->appendChild($error);
            }
        }
        return array($successfully => "true");

        }
    }
}