<?php

namespace razik440\googlecontacts\objects;

class Contact
{
    public function __construct($contactDetails)
    {
        foreach ($contactDetails as $key => $value) {
            $this->$key = $value;
        }
    }
}
