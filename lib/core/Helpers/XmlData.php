<?php

/**
 * Convert xml string to hash
 * @param mixed $str String
 * @return array
 */
function fromXML($str)
{
   $tmp = new xml_data($str);
   
   return $tmp->hash;
}

/**
 * Convert hash to xml string
 * @param mixed $hash hash
 * @return string
 */
function toXML($hash)
{
   $tmp = new xml_data($hash);

   return $tmp->string;
}
