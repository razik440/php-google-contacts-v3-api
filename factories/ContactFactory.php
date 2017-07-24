<?php

namespace razik440\googlecontacts\factories;

use DOMDocument;
use razik440\googlecontacts\helpers\GoogleHelper;
use razik440\googlecontacts\objects\Contact;

abstract class ContactFactory
{

    private static function ObjToArray($obj)
    {
        return json_decode(json_encode($obj), true);
    }

    public static function getAll($customConfig = NULL)
    {
        $client = GoogleHelper::getClient($customConfig);

        $req = new \Google_Http_Request('https://www.google.com/m8/feeds/contacts/default/full?max-results=10000&updated-min=2007-03-16T00:00:00&alt=json');

        $val = $client->getAuth()->authenticatedRequest($req);

        $response = json_decode($val->getResponseBody());

        $contactsArray = array();

        foreach ($response->feed->entry as $contact){
            $contact = self::ObjToArray($contact);
            $parseContact = array();

            $explode = explode('/',$contact['link'][count($contact['link'])-1]['href']);
            $count = count($explode);

            $contactID = $explode[$count-2];
            $parseContact['edit'] = $explode[$count-1];
            $parseContact['updated'] = $contact['updated']['$t'];
            $parseContact['title'] = $contact['title']['$t'];
            if(isset($contact['content'])){
                $parseContact['content'] = $contact['content']['$t'];
            }
            if(isset($contact['gd$organization']) && !empty($contact['gd$organization'])){
                $org = array();
                if(isset($contact['gd$organization'][0]['gd$orgName'])){
                    $org['orgName'] = $contact['gd$organization'][0]['gd$orgName']['$t'];
                }else{
                    $org['orgName'] = '';
                }
                if(isset($contact['gd$organization'][0]['gd$orgTitle'])){
                    $org['orgTitle'] = $contact['gd$organization'][0]['gd$orgTitle']['$t'];
                }else{
                    $org['orgTitle'] = '';
                }
                $parseContact['organization'] = ['orgName'=>$org['orgName'],'orgTitle'=>$org['orgTitle']];
            }
            if(isset($contact['gd$email']) && !empty($contact['gd$email'])){
                foreach ($contact['gd$email'] as $email){
                    //dump($email);
                    if(isset($email['rel'])){
                        $email['rel'] = mb_substr($email['rel'],mb_strrpos($email['rel'],'#')+1, mb_strlen($email['rel'])-mb_strrpos($email['rel'],'#')-1);
                    }
                    if(isset($email['label'])){
                        $email['rel'] = mb_substr($email['label'],mb_strrpos($email['label'],'#'), mb_strlen($email['label'])-mb_strrpos($email['label'],'#'));
                    }
                    $parseContact['email'][] = $email;
                }
            }
            if(isset($contact['gd$phoneNumber']) && !empty($contact['gd$phoneNumber'])){
                foreach ($contact['gd$phoneNumber'] as $phone){
                    $phone['number'] = $phone['$t'];
                    unset($phone['uri'], $phone['$t']);
                    if(isset($phone['rel'])){
                        $phone['rel'] = mb_substr($phone['rel'],mb_strrpos($phone['rel'],'#')+1, mb_strlen($phone['rel'])-mb_strrpos($phone['rel'],'#')-1);
                    }
                    if(isset($phone['label'])){
                        $phone['rel'] = mb_substr($phone['label'],mb_strrpos($phone['label'],'#'), mb_strlen($phone['label'])-mb_strrpos($phone['label'],'#'));
                    }
                    $parseContact['phone'][] = $phone;
                }
            }
            //dd($parseContact);
		$contactsArray[$contactID] = $parseContact;
        }
        return $contactsArray;
    }

    public static function getBySelfURL($selfURL, $customConfig = NULL)
    {
        $client = GoogleHelper::getClient($customConfig);

        $req = new \Google_Http_Request($selfURL);

        $val = $client->getAuth()->authenticatedRequest($req);

        $response = $val->getResponseBody();

        dd($response);

        $xmlContact = simplexml_load_string($response);
        dd($xmlContact->asXML());
        $xmlContact->registerXPathNamespace('gd', 'http://schemas.google.com/g/2005');

        $xmlContactsEntry = $xmlContact;

        $contactDetails = array();

        $contactDetails['id'] = (string) $xmlContactsEntry->id;
        $contactDetails['name'] = (string) $xmlContactsEntry->title;

        foreach ($xmlContactsEntry->children() as $key => $value) {
            $attributes = $value->attributes();

            if ($key == 'link') {
                if ($attributes['rel'] == 'edit') {
                    $contactDetails['editURL'] = (string) $attributes['href'];
                } elseif ($attributes['rel'] == 'self') {
                    $contactDetails['selfURL'] = (string) $attributes['href'];
                } elseif ($attributes['rel'] == 'http://schemas.google.com/contacts/2008/rel#edit-photo') {
                    $contactDetails['photoURL'] = (string) $attributes['href'];
                }
            }
        }

        $contactGDNodes = $xmlContactsEntry->children('http://schemas.google.com/g/2005');
        foreach ($contactGDNodes as $key => $value) {
            switch ($key) {
                case 'organization':
                    $contactDetails[$key]['orgName'] = (string) $value->orgName;
                    $contactDetails[$key]['orgTitle'] = (string) $value->orgTitle;
                    break;
                case 'email':
                    $attributes = $value->attributes();
                    $emailadress = (string) $attributes['address'];
                    $emailtype = (string)$attributes['label'];
                    $contactDetails[$key][] = ['type' => $emailtype, 'email' => $emailadress];
                    break;
                case 'phoneNumber':
                    $attributes = $value->attributes();
                    $uri = (string) $attributes['uri'];
                    $type = (string)$attributes['label'];
                    $e164 = substr(strstr($uri, ':'), 1);
                    $contactDetails[$key][] = ['type' => $type, 'number' => $e164];
                    break;
                default:
                    $contactDetails[$key] = (string) $value;
                    break;
            }
        }

        return new Contact($contactDetails);
    }

    public static function submitUpdates(Contact $updatedContact, $customConfig = NULL)
    {
        $client = GoogleHelper::getClient($customConfig);

        $req = new \Google_Http_Request($updatedContact->selfURL);

        $val = $client->getAuth()->authenticatedRequest($req);

        $response = $val->getResponseBody();

        $responseDOM = new DOMDocument();
        $responseDOM->loadXML($response);
        $responseDOMroot = $responseDOM->documentElement;

        foreach ($responseDOMroot->getElementsByTagNameNS('http://schemas.google.com/g/2005','email') as $element) {
            $element->parentNode->removeChild($element);
        }
        foreach ($responseDOMroot->getElementsByTagNameNS('http://schemas.google.com/g/2005','phoneNumber') as $element) {
            $element->parentNode->removeChild($element);
        }

        $upd = ['email'=>$updatedContact->email,'phone'=>$updatedContact->phoneNumber];
        foreach ($upd as $type => $data){
            if($type == 'email'){
                foreach ($data as $value){
                    $domElement = $responseDOM->createElement('gd:email');
                    if(!in_array($value['type'],['home','work'])){
                        $value['type'] = 'other';
                    }
                    $domAttribute = $responseDOM->createAttribute('rel');
                    $domAttribute->value = 'http://schemas.google.com/g/2005#'.$value['type'];
                    $domElement->appendChild($domAttribute);
                    $domAttribute = $responseDOM->createAttribute('address');
                    $domAttribute->value = $value['email'];
                    $domElement->appendChild($domAttribute);
                    //$domAttribute = $responseDOM->createAttribute('primary');
                    //$domAttribute->value = 'true';
                    $domElement->appendChild($domAttribute);
                    $responseDOMroot->appendChild($domElement);
                }
            }elseif($type == 'phone'){
                foreach ($data as $value){
                    $domElement = $responseDOM->createElement('gd:phoneNumber',$value['number']);
                    if(!in_array($value['type'],['home','work','mobile','fax'])){
                        $value['type'] = 'other';
                    }
                    $domAttribute = $responseDOM->createAttribute('rel');
                    $domAttribute->value = 'http://schemas.google.com/g/2005#'.$value['type'];
                    $domElement->appendChild($domAttribute);
                    //$domAttribute = $responseDOM->createAttribute('primary');
                    //$domAttribute->value = 'true';
                    $domElement->appendChild($domAttribute);
                    $responseDOMroot->appendChild($domElement);
                }
            }
        }

        $updatedXML = $responseDOM->saveXML();

        $req = new \Google_Http_Request($updatedContact->editURL);
        $req->setRequestHeaders(array('content-type' => 'application/atom+xml; charset=UTF-8; type=feed'));
        $req->setRequestMethod('PUT');
        $req->setPostBody($updatedXML);

        $val = $client->getAuth()->authenticatedRequest($req);

        $response = $val->getResponseBody();

        $xmlContact = simplexml_load_string($response);

        $xmlContact->registerXPathNamespace('gd', 'http://schemas.google.com/g/2005');

        $xmlContactsEntry = $xmlContact;

        $contactDetails = array();

        $contactDetails['id'] = (string) $xmlContactsEntry->id;
        $contactDetails['name'] = (string) $xmlContactsEntry->title;

        foreach ($xmlContactsEntry->children() as $key => $value) {
            $attributes = $value->attributes();

            if ($key == 'link') {
                if ($attributes['rel'] == 'edit') {
                    $contactDetails['editURL'] = (string) $attributes['href'];
                } elseif ($attributes['rel'] == 'self') {
                    $contactDetails['selfURL'] = (string) $attributes['href'];
                }
            }
        }

        $contactGDNodes = $xmlContactsEntry->children('http://schemas.google.com/g/2005');

        foreach ($contactGDNodes as $key => $value) {
            $attributes = $value->attributes();

            if ($key == 'email') {
                $contactDetails[$key] = (string) $attributes['address'];
            } else {
                $contactDetails[$key] = (string) $value;
            }
        }

        return new Contact($contactDetails);
    }

    public static function create($request, $customConfig = NULL)
    {
        $doc = new \DOMDocument();
        $doc->formatOutput = true;
        $entry = $doc->createElement('atom:entry');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:gd', 'http://schemas.google.com/g/2005');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:gd', 'http://schemas.google.com/g/2005');
        $doc->appendChild($entry);

        if(isset($request['name']) && '' != $request['name']){
            $title = $doc->createElement('title', $request['name']);
            $entry->appendChild($title);
        }
        if(isset($request['comment']) && '' != $request['comment']){
            $content = $doc->createElement('atom:content', $request['comment']);
            $content->setAttribute('type', 'text');
            $entry->appendChild($content);
        }
        if(isset($request['email']) && !empty($request['email'])){
            foreach ($request['email'] as $email){
                $mail = $doc->createElement('gd:email');
                $mail->setAttribute('rel', 'http://schemas.google.com/g/2005#'.$email['type']);
                if($email['primary'] === 'true'){
                    $mail->setAttribute('primary', 'true');
                }
                $mail->setAttribute('address',$email['mail']);
                $entry->appendChild($mail);
            }
        }
        if(isset($request['phone']) && !empty($request['phone'])){
            foreach ($request['phone'] as $phone){
                $phoneNumber = $doc->createElement('gd:phoneNumber', $phone['number']);
                $phoneNumber->setAttribute('rel', 'http://schemas.google.com/g/2005#'.$phone['type']);
                if($phone['primary'] === 'true'){
                    $phoneNumber->setAttribute('primary', 'true');
                }
                $entry->appendChild($phoneNumber);
            }
        }
        if(isset($request['organization']) && !empty($request['organization'])){
            $org = $doc->createElement('gd:organization');
            $org->setAttribute('rel', 'http://schemas.google.com/g/2005#work');
            //$org->setAttribute('label', 'Work');
            $org->setAttribute('primary', 'true');
            if(isset($request['organization']['name']) && '' != $request['organization']['name']){
                $org->appendChild($doc->createElement('gd:orgName', $request['organization']['name']));
            }
            if(isset($request['organization']['title']) && '' != $request['organization']['title']){
                $org->appendChild($doc->createElement('gd:orgTitle', $request['organization']['title']));
            }
            $entry->appendChild($org);
        }
        $xmlToSend = $doc->saveXML();

        $client = GoogleHelper::getClient($customConfig);

        $req = new \Google_Http_Request('https://www.google.com/m8/feeds/contacts/default/full');
        $req->setRequestHeaders(array('content-type' => 'application/atom+xml; charset=UTF-8; type=feed'));
        $req->setRequestMethod('POST');
        $req->setPostBody($xmlToSend);

        $val = $client->getAuth()->authenticatedRequest($req);

        $response = $val->getResponseBody();

        $xmlContact = simplexml_load_string($response);
        $xmlContact->registerXPathNamespace('gd', 'http://schemas.google.com/g/2005');

        $xmlContactsEntry = $xmlContact;

        $error = (array)$xmlContactsEntry->error;
        if(isset($error) && !empty($error)){
           return false;
        }

        $contactDetails = array();
        foreach ($xmlContactsEntry->children() as $key => $value) {
            $attributes = $value->attributes();
            if ($key == 'link') {
                if ($attributes['rel'] == 'edit') {
                    $contactDetails['editURL'] = (string) $attributes['href'];
                } elseif ($attributes['rel'] == 'self') {
                    $contactDetails['selfURL'] = (string) $attributes['href'];
                }
            }
        }
        return self::getContactID($contactDetails['editURL']);
    }

    private static function getContactID($editURL)
    {
        $edit = explode('/',$editURL);
        $count = count($edit);
        return ['id'=>$edit[$count-2],'edit'=>$edit[$count-1]];
    }

    public static function delete($id, $customConfig = NULL)
    {
        $client = GoogleHelper::getClient($customConfig);

        if(NULL === $customConfig){
            $customConfig = GoogleHelper::getConfig();
        }

        $req = new \Google_Http_Request($customConfig->editURL.implode('/',$id));
        $req->setRequestHeaders(array('content-type' => 'application/atom+xml; charset=UTF-8; type=feed'));
        $req->setRequestMethod('DELETE');

        $result = $client->getAuth()->authenticatedRequest($req);
        if(200 !== $result->getResponseHttpCode()){
            return false;
        }

        return true;
    }

    public static function getPhoto($photoURL, $customConfig = NULL)
    {
        $client = GoogleHelper::getClient($customConfig);
        $req = new \Google_Http_Request($photoURL);
        $req->setRequestMethod('GET');
        $val = $client->getAuth()->authenticatedRequest($req);
        $response = $val->getResponseBody();
        return $response;
    }
}
