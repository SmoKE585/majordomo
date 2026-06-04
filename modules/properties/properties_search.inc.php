<?php
/*
* @version 0.1 (auto-set)
*/

 global $session;
  if ($this->owner->name=='panel') {
   $out['CONTROLPANEL']=1;
  }
  $qry="1";
  // search filters
  if (IsSet($this->class_id)) {
   $class_id=$this->class_id;
   $qry.=" AND properties.CLASS_ID='".$this->class_id."'";

   include_once(DIR_MODULES.'classes/classes.class.php');
   $cl=new classes();
   $out['PARENT_PROPERTIES']=$cl->getParentProperties($this->class_id);
   if (!isset($out['PARENT_PROPERTIES'][0]['ID'])) {
    unset($out['PARENT_PROPERTIES']);
   }

  } else {
   global $class_id;
  }
  // QUERY READY
  global $save_qry;
  if ($save_qry) {
   $qry=$session->data['properties_qry'];
  } else {
   $session->data['properties_qry']=$qry;
  }
  if (!$qry) $qry="1";
  // FIELDS ORDER
  global $sortby;
  if (!$sortby) {
   $sortby=isset($session->data['properties_sort'])?$session->data['properties_sort']:'';
  } else {
   if ($session->data['properties_sort']==$sortby) {
    if (Is_Integer(strpos($sortby, ' DESC'))) {
     $sortby=str_replace(' DESC', '', $sortby);
    } else {
     $sortby=$sortby." DESC";
    }
   }
   $session->data['properties_sort']=$sortby;
  }
  if (!$sortby) $sortby="properties.TITLE";
  if ($sortby=='TITLE') {
   $sortby='properties.TITLE';
  } elseif ($sortby=='TITLE DESC') {
   $sortby='properties.TITLE DESC';
  }
  $out['SORTBY']=$sortby;
  // SEARCH RESULTS
  $res=SQLSelect("SELECT properties.*, classes.TITLE AS CLASS_TITLE FROM properties LEFT JOIN classes ON classes.ID=properties.CLASS_ID WHERE $qry ORDER BY $sortby");
  if ($res[0]['ID']) {
   colorizeArray($res);
   $total=count($res);
   $out['RESULT_TOTAL']=$total;
   for($i=0;$i<$total;$i++) {
    // some action for every record if required
   }
   $out['RESULT']=$res;
  }
  if (isset($out['PARENT_PROPERTIES'][0]['ID'])) {
   $out['PARENT_PROPERTIES_TOTAL']=count($out['PARENT_PROPERTIES']);
  }


?>
