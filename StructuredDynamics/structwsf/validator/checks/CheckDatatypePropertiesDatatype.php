<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\OntologyReadQuery;
  use \StructuredDynamics\structwsf\php\api\ws\ontology\read\GetSuperClassesFunction;
  use \StructuredDynamics\structwsf\php\api\ws\crud\read\CrudReadQuery;
  
  class CheckDatatypePropertiesDatatype extends Check
  {
    function __construct()
    { 
      $this->name = 'Datatype Properties Datatype Check';
      $this->description = 'Make sure that all the datatype properties used to describe the records uses the proper datatype range as defined in the ontologies';
    }
    
    public function run()
    {
      cecho("\n\n");
      
      cecho("Data validation test: ".$this->description."...\n\n", 'LIGHT_BLUE');

      $sparql = new SparqlQuery($this->network);

      $from = '';
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $from .= 'from named <'.$dataset.'> ';
      }
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $from .= 'from <'.$ontology.'> ';
      }
      
      // Get the list of all the datatype properties used within the datasets
      $sparql->mime("application/sparql-results+json")
             ->query('select distinct ?p ?range
                      '.$from.'
                      where
                      {
                        graph ?g {
                          ?s ?p ?o .
                          filter(!isIRI(?o))                          
                        } 
                        
                        optional
                        {                                                          
                          ?p <http://www.w3.org/2000/01/rdf-schema#range> ?range .
                        }
                      }')
             ->send();

      if($sparql->isSuccessful())
      {
        $results = json_decode($sparql->getResultset(), TRUE);    

        if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
        {
          $datatypeProperties = array();
          $thereAreEmptyRanges = FALSE;

          foreach($results['results']['bindings'] as $result)
          {
            $datatypeProperty = $result['p']['value'];
            
            $datatypeProperties[$datatypeProperty] = '';
            
            if(isset($result['range']))
            {
              $datatypeProperties[$datatypeProperty] = $result['range']['value'];
            }
            else
            {
              $thereAreEmptyRanges = TRUE;
            }
          }

          // Display warnings
          if($thereAreEmptyRanges)
          {
            cecho("The following datatype properties are used to describe records, but their datatype range is not specified in the ontologies. No further checks are performed against these datatype properties, but you may want to define it further and re-run this check:\n", 'YELLOW');
            
            foreach($datatypeProperties as $datatypeProperty => $range)
            {
              if(empty($range))
              {
                cecho('  -> object property: '.$datatypeProperty."\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'DATATYPE-PROPERTIES-DATATYPE-50',
                  'type' => 'warning',
                  'datatypeProperty' => $datatypeProperty,
                );                
              }
            }
          }

          // Now, for each datatype properties that have a datatype range defined,
          // we:
          // 
          //  (a) List all values used for a given property
          //  (b) For each of these values, make sure they comply with what is defined in the ontology as
          //      the range of the property
          //  (c) Then make sure that the value is valid according to the validators defined in this Check
          foreach($datatypeProperties as $datatypeProperty => $range)
          {
            // If the range is empty, don't validate anything further
            // We consider the values of time rdfs:Literal
            if(!empty($range) && $range != 'http://www.w3.org/2000/01/rdf-schema#Literal')
            {
              $values = array();
              
              $sparql = new SparqlQuery($this->network);

              $from = '';
              
              foreach($this->checkOnDatasets as $dataset)
              {
                $from .= 'from <'.$dataset.'> ';
              }
              
              $sparql->mime("application/sparql-results+json")
                     ->query('select distinct ?value datatype(?value) as ?value_type ?s
                              '.$from.'
                              where
                              {
                                ?s <'.$datatypeProperty.'> ?value.
                                filter(!isIRI(?value))
                              }')
                     ->send();

              if($sparql->isSuccessful())
              {
                $results = json_decode($sparql->getResultset(), TRUE);
                $values = array();    
                
                if(isset($results['results']['bindings']) && count($results['results']['bindings']) > 0)
                {
                  foreach($results['results']['bindings'] as $result)
                  {
                    $value = $result['value']['value'];
                    
                    $type = '';
                    if(isset($result['value_type']))
                    {
                      $type = $result['value_type']['value'];
                    }                        
                    
                    $s = '';
                    if(isset($result['s']))
                    {
                      $s = $result['s']['value'];
                    }                        
                    
                    $values[] = array(
                      'value' => $value,
                      'type' => $type,
                      'affectedRecord' => $s
                    );
                  }
                }

                // For each value/type(s), we do validate that the range is valid
                foreach($values as $value)
                {
                  // First, check if we have a type defined for the value. If not, then we infer it is rdfs:Literal
                  if(empty($value['type']))
                  {
                    $value['type'] = array('http://www.w3.org/2000/01/rdf-schema#Literal');
                  }
                  
                  // Then, check if the $range and the $value['type'] directly match
                  // Note: Here we check if xsd:string is defined, if it is, then we ignore.
                  //       This is required since if no datatype is specified in the RDF document
                  //       Virtuoso is considering it to be a xsd:string
                  if($range != $value['type'] && $value['type'] != 'http://www.w3.org/2001/XMLSchema#string')
                  {
                    // Here we need to take a few exceptions into account. 
                    // Virtuoso does internally change a few defined datatype into xsd:int (or others) when equivalent.
                    // We have to mute such "false positive" errors
                    if(($range == 'http://www.w3.org/2001/XMLSchema#boolean' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#unsignedByte' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#nonPositiveInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#positiveInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#negativeInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#unsignedLong' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#nonNegativeInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#unsignedShort' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                       ($range == 'http://www.w3.org/2001/XMLSchema#unsignedLong' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#decimal'))
                    {
                      continue;
                    }
                    
                    cecho('  -> Datatype property "'.$datatypeProperty.'" doesn\'t match datatype range "'.$range.'" for value \''.$value['value'].'\' with defined type \''.$value['type'].'\' '."\n", 'LIGHT_RED');
                    
                    // If it doesn't match, then we report an error directly
                    $this->errors[] = array(
                      'id' => 'DATATYPE-PROPERTIES-DATATYPE-100',
                      'type' => 'error',
                      'datatypeProperty' => $datatypeProperty,
                      'expectedDatatype' => $range,
                      'valueDatatype' => $value['type'],
                      'value' => $value['value'],
                      'affectedRecord' => $value['affectedRecord']
                    );                      
                    
                    continue;
                  }
                  else
                  {
                    // If then match, then we make sure that the value is valid according to the
                    // internal Check datatype validation tests

                    $datatypeValidationError = FALSE;
                    
                    switch($value['type'])
                    {
                      case "http://www.w3.org/2001/XMLSchema#base64Binary":
                        if(!$this->validateBase64Binary($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#boolean":
                        if(!$this->validateBoolean($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#byte":
                        if(!$this->validateByte($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#dateTimeStamp":
                        if(!$this->validateDateTimeStampISO8601($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#dateTime":
                        if(!$this->validateDateTimeISO8601($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#decimal":
                        if(!$this->validateDecimal($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#double":
                        if(!$this->validateDouble($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#float":
                        if(!$this->validateFloat($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#hexBinary":
                        if(!$this->validateHexBinary($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#int":
                        if(!$this->validateInt($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#integer":
                        if(!$this->validateInteger($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#language":
                        if(!$this->validateLanguage($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#long":
                        if(!$this->validateLong($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#Name":
                        if(!$this->validateName($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#NCName":
                        if(!$this->validateNCName($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#negativeInteger":
                        if(!$this->validateNegativeInteger($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#NMTOKEN":
                        if(!$this->validateNMTOKEN($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#nonNegativeInteger":
                        if(!$this->validateNonNegativeInteger($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#nonPositiveInteger":
                        if(!$this->validateNonPositiveInteger($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#normalizedString":
                        if(!$this->validateNormalizedString($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/1999/02/22-rdf-syntax-ns#PlainLiteral":
                        if(!$this->validatePlainLiteral($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#positiveInteger":
                        if(!$this->validatePositiveInteger($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#short":
                        if(!$this->validateShort($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#string":
                        if(!$this->validateString($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#token":
                        if(!$this->validateToken($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#unsignedByte":
                        if(!$this->validateUnsignedByte($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#unsignedInt":
                        if(!$this->validateUnsignedInt($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#unsignedLong":
                        if(!$this->validateUnsignedLong($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#unsignedShort":
                        if(!$this->validateUnsignedShort($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral":
                        if(!$this->validateXMLLiteral($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      case "http://www.w3.org/2001/XMLSchema#anyURI":
                        if(!$this->validateAnyURI($value['value']))
                        {
                          $datatypeValidationError = TRUE;
                        }
                      break;
                      
                      default:
                        // unknown types are consider rdfs:Literal
                      break;
                    }
                    
                    if($datatypeValidationError)
                    {
                      cecho('  -> Datatype property "'.$datatypeProperty.'" does match datatype range "'.$range.'" for value \''.$value['value'].'\' but an invalid value as been specified '."\n", 'LIGHT_RED');
                      
                      // If it doesn't match, then we report an error directly
                      $this->errors[] = array(
                        'id' => 'DATATYPE-PROPERTIES-DATATYPE-101',
                        'type' => 'error',
                        'datatypeProperty' => $datatypeProperty,
                        'expectedDatatype' => $range,
                        'invalidValue' => $value['value'],
                        'affectedRecord' => $value['affectedRecord']
                      );                                                  
                    }
                  }
                }
              }
              else
              {
                cecho("We couldn't get the list of values for the $datatypePropety property\n", 'YELLOW');
                
                $this->errors[] = array(
                  'id' => 'DATATYPE-PROPERTIES-DATATYPE-52',
                  'type' => 'warning',
                );           
              }
            }
          }
        }
      }
      else
      {
        cecho("We couldn't get the list of datatype properties from the structWSF instance\n", 'YELLOW');
        
        $this->errors[] = array(
          'id' => 'DATATYPE-PROPERTIES-DATATYPE-51',
          'type' => 'warning',
        );           
      }
    }
    
    public function fix()
    {
    }    
    
    public function outputXML()
    {
      if(count($this->errors) <= 0)
      {
        return('');
      }
      
      $xml = "  <check>\n";
      
      $xml .= "    <name>".$this->name."</name>\n";      
      $xml .= "    <description>".$this->description."</description>\n";      
      $xml .= "    <onDatasets>\n";
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $xml .= "      <dataset>".$dataset."</dataset>\n";
      }
      
      $xml .= "    </onDatasets>\n";

      $xml .= "    <usingOntologies>\n";
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $xml .= "      <ontology>".$ontology."</ontology>\n";
      }
      
      $xml .= "    </usingOntologies>\n";

      $xml .= "    <validationWarnings>\n";
      
      foreach($this->errors as $error)
      {
        if($error['type'] == 'warning')
        {
          $xml .= "      <warning>\n";
          $xml .= "        <id>".$error['id']."</id>\n";
          
          if(!empty($error['datatypeProperty']))
          {
            $xml .= "        <datatypeProperty>".$error['datatypeProperty']."</datatypeProperty>\n";
          }
          
          $xml .= "      </warning>\n";
        }
      }
      
      $xml .= "    </validationWarnings>\n";
      
      $xml .= "    <validationErrors>\n";
      
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $xml .= "      <error>\n";
          $xml .= "        <id>".$error['id']."</id>\n";
          $xml .= "        <datatypeProperty>".$error['datatypeProperty']."</datatypeProperty>\n";
          
          if(isset($error['expectedDatatype']) && !empty($error['expectedDatatype']))
          {
            $xml .= "        <expectedDatatype>".$error['expectedDatatype']."</expectedDatatype>\n";
          }
          
          if(isset($error['valueDatatype']) && !empty($error['valueDatatype']))
          {
            $xml .= "        <valueDatatype>".$error['valueDatatype']."</valueDatatype>\n";
          }

          if(isset($error['value']) && !empty($error['value']))
          {
            $xml .= "        <value>".$error['value']."</value>\n";
          }

          if(isset($error['affectedRecord']) && !empty($error['affectedRecord']))
          {
            $xml .= "        <affectedRecord>".$error['affectedRecord']."</affectedRecord>\n";
          }
          
          $xml .= "      </error>\n";
        }
      }
      
      $xml .= "    </validationErrors>\n";
      
      $xml .= "  </check>\n";
      
      return($xml);
    }    
    
    public function outputJSON()
    {
      if(count($this->errors) <= 0)
      {
        return('');
      }
      
      $json = "  {\n";
      
      $json .= "    \"name\": \"".$this->name."\",\n";      
      $json .= "    \"description\": \"".$this->description."\",\n";      
      $json .= "    \"onDatasets\": [\n";
      
      foreach($this->checkOnDatasets as $dataset)
      {
        $json .= "      \"".$dataset."\",\n";
      }
      
      $json = substr($json, 0, strlen($json) - 2)."\n";
      
      $json .= "    ],\n";

      $json .= "    \"usingOntologies\": [\n";
      
      foreach($this->checkUsingOntologies as $ontology)
      {
        $json .= "      \"".$ontology."\",\n";
      }

      $json = substr($json, 0, strlen($json) - 2)."\n";
      
      $json .= "    ],\n";
    
      $json .= "    \"validationWarnings\": [\n";
      
      $foundWarnings = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'warning')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          
          if(!empty($error['datatypeProperty']))
          {
            $json .= "        \"datatypeProperty\": \"".$error['datatypeProperty']."\",\n";
          }
          
          $json = substr($json, 0, strlen($json) - 2)."\n";
          
          $json .= "      },\n";
          
          $foundWarnings = TRUE;
        }
      }
      
      if($foundWarnings)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      }
      
      $json .= "    ],\n";    
      
      $json .= "    \"validationErrors\": [\n";
    
      $foundErrors = FALSE;
      foreach($this->errors as $error)
      {
        if($error['type'] == 'error')
        {
          $json .= "      {\n";
          $json .= "        \"id\": \"".$error['id']."\",\n";
          $json .= "        \"datatypeProperty\": \"".$error['datatypeProperty']."\",\n";
          
          if(isset($error['expectedDatatype']) && !empty($error['expectedDatatype']))
          {
            $json .= "        \"expectedDatatype\": \"".$error['expectedDatatype']."\",\n";
          }
          
          if(isset($error['valueDatatype']) && !empty($error['valueDatatype']))
          {
            $json .= "        \"valueDatatype\": \"".$error['valueDatatype']."\",\n";
          }
          
          if(isset($error['value']) && !empty($error['value']))
          {
            $json .= "        \"value\": \"".$error['value']."\",\n";
          }
          
          if(isset($error['affectedRecord']) && !empty($error['affectedRecord']))
          {
            $json .= "        \"affectedRecord\": \"".$error['affectedRecord']."\",\n";
          }
          
          $json = substr($json, 0, strlen($json) - 2)."\n";
          
          $json .= "      },\n";
          
          $foundErrors = TRUE;
        }
      }
      
      if($foundErrors)
      {
        $json = substr($json, 0, strlen($json) - 2)."\n";
      } 
            
      
      $json .= "    ]\n";
      
      $json .= "  }\n";
      
      return($json);      
    } 
    
    /**
    * Validate xsd:dateTime
    */
    private function validateDateTimeISO8601($value)
    {
      if(preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/', $value) > 0) 
      {
        return(TRUE);
      } 
      else 
      {
        return(FALSE);
      }
    }
    
    /**
    * Validate xsd:base64Binary
    */
    private function validateBase64Binary($value)
    {
      if(base64_encode(base64_decode($value)) === $value)
      {
        return(TRUE);
      } 
      else 
      {
        return(FALSE);
      }
    }
    
    /**
    * validate xsd:unsignedInt
    * 
    * @param mixed $value
    */
    private function validateUnsignedInt($value)
    {
      if(is_int($value) && $value >= 0 && $value <= 4294967295)   
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }
    
    /**
    * Validate xsd:dateTimeStamp
    */
    private function validateDateTimeStampISO8601($value)
    {
      if($this->validateDateTimeISO8601($value))
      {
        if(preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)(\17[0-5]\d([\.,]\d+)?)([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?))?)?$/', $value) > 0) 
        {
          return(TRUE);
        } 
        else 
        {
          return(FALSE);
        }            
      }
      else
      {
        return(FALSE);
      }
    }
    
    /**
    * Validate xsd:anyURI
    */
    private function validateAnyURI($value)
    {
      return((bool) preg_match('/^[a-z](?:[-a-z0-9\+\.])*:(?:\/\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:])*@)?(?:\[(?:(?:(?:[0-9a-f]{1,4}:){6}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|::(?:[0-9a-f]{1,4}:){5}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){4}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4}:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){3}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,2}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){2}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,3}[0-9a-f]{1,4})?::[0-9a-f]{1,4}:(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,4}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,5}[0-9a-f]{1,4})?::[0-9a-f]{1,4}|(?:(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{1,4})?::)|v[0-9a-f]+[-a-z0-9\._~!\$&\'\(\)\*\+,;=:]+)\]|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}|(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=@])*)(?::[0-9]*)?(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|\/(?:(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*)?|(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|(?!(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])))(?:\?(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}|\x{100000}-\x{10FFFD}\/\?])*)?(?:\#(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\/\?])*)?$/iu', $value));
    }
    
    /**
    * Validate xsd:boolean
    */
    private function validateBoolean($value)
    {
      switch((string)$value)
      {
        case '0':
        case '1':
        case 'true':
        case 'false':
          return(TRUE);
        break;
      }
      
      return(FALSE);
    }

    /**
    * Validate xsd:byte
    */    
    private function validateByte($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= -128 && $value <= 127)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }    
    

    /**
    * Validate xsd:unsignedByte
    */    
    private function validateUnsignedByte($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= 0 && $value <= 255)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }       
    
    /**
    * Validate xsd:decimal
    */
    private function validateDecimal($value)
    {
      return((bool) preg_match('/^[+-]?(\d*\.\d+([eE]?[+-]?\d+)?|\d+[eE][+-]?\d+)$/', $value));
    }
    
    /**
    *  Validate xsd:double
    */
    private function validateDouble($value)
    {
      if($value == 'NaN' || $value == 'INF' || $value == '-INF')
      {
        return(TRUE);
      }
      
      return(is_numeric($value));
    }

    /**
    *  Validate xsd:float
    */
    private function validateFloat($value)
    {
      if($value == 'NaN' || $value == 'INF' || $value == '-INF')
      {
        return(TRUE);
      }
      
      return(is_numeric($value));
    }
    
    /**
    * Validate xsd:int
    */
    private function validateInt($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= -2147483648 && $value <= 2147483647)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }
    
    /**
    * Validate xsd:integer
    */
    private function validateInteger($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }    
    
    /**
    * Validate xsd:nonNegativeInteger
    */
    private function validateNonNegativeInteger($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= 0)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    } 
           
    /**
    * Validate xsd:nonPositiveInteger
    */
    private function validateNonPositiveInteger($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value <= 0)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }   
    
    
    /**
    * Validate xsd:positiveInteger
    */
    private function validatePositiveInteger($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= 1)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    } 
           
    /**
    * Validate xsd:negativeInteger
    */
    private function validateNegativeInteger($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value <= -1)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }      
    
    /**
    * Validate xsd:short
    */
    private function validateShort($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= -32768 && $value <= 32767)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }  
    
    
    /**
    * Validate xsd:unsignedShort
    */
    private function validateUnsignedShort($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= 0 && $value <= 65535)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }      
              
    /**
    * Validate xsd:long
    */
    private function validateLong($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= -9223372036854775808 && $value <= 9223372036854775807)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }
                  
    /**
    * Validate xsd:unsignedLong
    */
    private function validateUnsignedLong($value)
    {
      $value = filter_var($value, FILTER_VALIDATE_INT);
      
      if($value !== FALSE && $value >= 0 && $value <= 18446744073709551615)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }  
    
    /**
    * Validate xsd:hexBinary
    */
    private function validateHexBinary($value)
    {
      if(ctype_xdigit($value) && strlen($value) % 2 == 0)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }  
    
    /**
    * Validate xsd:language
    */
    private function validateLanguage($value)
    {
      return((bool) preg_match('/^[a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*$/', $value));
    }
    
    /**
    * Validate xsd:Name
    */
    private function validateName($value)
    {
      return((bool) preg_match('/^[a-zA-Z_:]{1}[a-zA-z0-9_:\-\.]*$/', $value));
    }
    
    /**
    * Validate xsd:NCName
    */
    private function validateNCName($value)
    {
      return((bool) preg_match('/^[a-zA-Z_]{1}[a-zA-z0-9_\-\.]*$/', $value));
    }
    
    /**
    * Validate xsd:NMTOKEN
    */
    private function validateNMTOKEN($value)
    {
      return((bool) preg_match('/^[\s]*[a-zA-z0-9_\-\.:]+[\s]*$/', $value));
    }
    
    /**
    * Validate xsd:string
    */
    private function validateString($value)
    {
      $value = "<?xml version='1.0'?><test>".$value."</test>";
      
      libxml_use_internal_errors(true);
      
      if(simplexml_load_string($value) !== FALSE)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }

    /**
    * Validate xsd:token
    */
    private function validateToken($value)
    {
      $value = str_replace(array("\n", "\r", "\t"), ' ', $value);
      
      $value = preg_replace('/\s+/', ' ', $value);
      
      $value = trim($value);
      
      $value = "<?xml version='1.0'?><test>".$value."</test>";
      
      libxml_use_internal_errors(true);
      
      if(simplexml_load_string($value) !== FALSE)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }

    /**
    * Validate xsd:normalizedString
    */
    private function validateNormalizedString($value)
    {
      $value = str_replace(array("\n", "\r", "\t"), ' ', $value);
      $value = "<?xml version='1.0'?><test>".$value."</test>";
      
      libxml_use_internal_errors(true);
      
      if(simplexml_load_string($value) !== FALSE)
      {
        return(TRUE);
      }
      else
      {
        return(FALSE);
      }
    }
      
    /**
    * Validate rdf:XMLLiteral
    */
    private function validateXMLLiteral($value)
    {
      return($this->validateString($value));
    }

    /**
    * Validate rdf:PlainLiteral
    */
    private function validatePlainLiteral($value)
    {
      return((bool) preg_match('/^.*@([a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*)*$/', $value));
    }    
    
    private function testValidators()
    {
      // test xsd:unsignedInt validator
      if($this->validateUnsignedInt(0) !== TRUE){ cecho("Validator testing issue: xsd:unsignedInt with 4294967296\n", 'RED'); }
      if($this->validateUnsignedInt(1) !== TRUE){ cecho("Validator testing issue: xsd:unsignedInt with 1\n", 'RED'); }
      if($this->validateUnsignedInt(4294967295) !== TRUE){ cecho("Validator testing issue: xsd:unsignedInt with 4294967295\n", 'RED'); }
      if($this->validateUnsignedInt(-1) !== FALSE){ cecho("Validator testing issue: xsd:unsignedInt with -1\n", 'RED'); }
      if($this->validateUnsignedInt(4294967296) !== FALSE){ cecho("Validator testing issue: xsd:unsignedInt with 4294967296\n", 'RED'); }
      
      // test xsd:base64Binary
      if($this->validateBase64Binary('dGhpcyBpcyBhIHRlc3Q=') !== TRUE){ cecho("Validator testing issue: xsd:base64Binary with 'dGhpcyBpcyBhIHRlc3Q='\n", 'RED'); }
      if($this->validateBase64Binary('dGhpcyBpcyBhIHRlc3Q-') !== FALSE){ cecho("Validator testing issue: xsd:base64Binary with 'dGhpcyBpcyBhIHRlc3Q-'\n", 'RED'); }
      
      // test xsd:dateTime
      if($this->validateDateTimeISO8601('1997') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997-07'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07-16') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997-07-16'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07-16T19:20+01:00') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997-07-16T19:20+01:00'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07-16T19:20:30+01:00') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997-07-16T19:20:30+01:00'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07-16T19:20:30.45+01:00') !== TRUE){ cecho("Validator testing issue: xsd:dateTime with '1997-07-16T19:20:30.45+01:00'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997-07-') !== FALSE){ cecho("Validator testing issue: xsd:dateTime with '1997-07-'\n", 'RED'); }
      if($this->validateDateTimeISO8601('19') !== FALSE){ cecho("Validator testing issue: xsd:dateTime with '19'\n", 'RED'); }
      if($this->validateDateTimeISO8601('1997 06 24') !== FALSE){ cecho("Validator testing issue: xsd:dateTime with '1997 06 24'\n", 'RED'); }
      if($this->validateDateTimeISO8601('') !== FALSE){ cecho("Validator testing issue: xsd:dateTime with ''\n", 'RED'); }
      
      // test xsd:dateTimeStamp
      if($this->validateDateTimeStampISO8601('2004-04-12T13:20:00-05:00') !== TRUE){ cecho("Validator testing issue: xsd:dateTimeStamp with '2004-04-12T13:20:00-05:00'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('2004-04-12T13:20:00Z') !== TRUE){ cecho("Validator testing issue: xsd:dateTimeStamp with '2004-04-12T13:20:00Z'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('2004-04-12T13:20:00') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '2004-04-12T13:20:00'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('2004-04-12T13:00Z') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '2004-04-12T13:00Z'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('2004-04-12Z') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '2004-04-12Z'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('1997-07-') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '1997-07-'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('19') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '19'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('1997 06 24') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with '1997 06 24'\n", 'RED'); }
      if($this->validateDateTimeStampISO8601('') !== FALSE){ cecho("Validator testing issue: xsd:dateTimeStamp with ''\n", 'RED'); }
      
      // test xsd:anyURI
      if($this->validateAnyURI('http://datypic.com') !== TRUE){ cecho("Validator testing issue: xsd:anyURI with 'http://datypic.com'\n", 'RED'); }
      if($this->validateAnyURI('mailto:info@datypic.com') !== TRUE){ cecho("Validator testing issue: xsd:anyURI with 'mailto:info@datypic.com'\n", 'RED'); }
      if($this->validateAnyURI('http://datypic.com/prod.html#shirt') !== TRUE){ cecho("Validator testing issue: xsd:anyURI with 'http://datypic.com/prod.html#shirt'\n", 'RED'); }
      if($this->validateAnyURI('urn:example:org') !== TRUE){ cecho("Validator testing issue: xsd:anyURI with 'urn:example:org'\n", 'RED'); }
      if($this->validateAnyURI('http://datypic.com#frag1#frag2') !== FALSE){ cecho("Validator testing issue: xsd:anyURI with 'http://datypic.com#frag1#frag2'\n", 'RED'); }
      if($this->validateAnyURI('http://datypic.com#f% rag') !== FALSE){ cecho("Validator testing issue: xsd:anyURI with 'http://datypic.com#f% rag'\n", 'RED'); }
      if($this->validateAnyURI('') !== FALSE){ cecho("Validator testing issue: xsd:anyURI with ''\n", 'RED'); }
      
      // test xsd:boolean
      if($this->validateBoolean('true') !== TRUE){ cecho("Validator testing issue: xsd:boolean with 'true'\n", 'RED'); }
      if($this->validateBoolean('false') !== TRUE){ cecho("Validator testing issue: xsd:boolean with 'false'\n", 'RED'); }
      if($this->validateBoolean('0') !== TRUE){ cecho("Validator testing issue: xsd:boolean with '0'\n", 'RED'); }
      if($this->validateBoolean('1') !== TRUE){ cecho("Validator testing issue: xsd:boolean with '1'\n", 'RED'); }
      if($this->validateBoolean('TRUE') !== FALSE){ cecho("Validator testing issue: xsd:boolean with 'TRUE'\n", 'RED'); }
      if($this->validateBoolean('T') !== FALSE){ cecho("Validator testing issue: xsd:boolean with 'T'\n", 'RED'); }
      if($this->validateBoolean('') !== FALSE){ cecho("Validator testing issue: xsd:boolean with ''\n", 'RED'); }
      
      // test xsd:byte
      if($this->validateByte('+3') !== TRUE){ cecho("Validator testing issue: xsd:byte with '+3'\n", 'RED'); }
      if($this->validateByte('122') !== TRUE){ cecho("Validator testing issue: xsd:byte with '122'\n", 'RED'); }
      if($this->validateByte('0') !== TRUE){ cecho("Validator testing issue: xsd:byte with '0'\n", 'RED'); }
      if($this->validateByte('-123') !== TRUE){ cecho("Validator testing issue: xsd:byte with '-123'\n", 'RED'); }
      if($this->validateByte('130') !== FALSE){ cecho("Validator testing issue: xsd:byte with '130'\n", 'RED'); }
      if($this->validateByte('3.0') !== FALSE){ cecho("Validator testing issue: xsd:byte with '3.0'\n", 'RED'); }
      
      // test xsd:unsignedByte
      if($this->validateUnsignedByte('+3') !== TRUE){ cecho("Validator testing issue: xsd:unsignedByte with '+3'\n", 'RED'); }
      if($this->validateUnsignedByte('122') !== TRUE){ cecho("Validator testing issue: xsd:unsignedByte with '122'\n", 'RED'); }
      if($this->validateUnsignedByte('0') !== TRUE){ cecho("Validator testing issue: xsd:unsignedByte with '0'\n", 'RED'); }
      if($this->validateUnsignedByte('-123') !== FALSE){ cecho("Validator testing issue: xsd:unsignedByte with '-123'\n", 'RED'); }
      if($this->validateUnsignedByte('256') !== FALSE){ cecho("Validator testing issue: xsd:unsignedByte with '256'\n", 'RED'); }
      if($this->validateUnsignedByte('3.0') !== FALSE){ cecho("Validator testing issue: xsd:unsignedByte with '3.0'\n", 'RED'); }
      
      // test xsd:decimal
      if($this->validateDecimal('3.0') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '3.0'\n", 'RED'); }
      if($this->validateDecimal('-3.0') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '-3.0'\n", 'RED'); }
      if($this->validateDecimal('+3.5') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '+3.5'\n", 'RED'); }
      if($this->validateDecimal('.3') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '.3'\n", 'RED'); }
      if($this->validateDecimal('-.3') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '-.3'\n", 'RED'); }
      if($this->validateDecimal('0003.0') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '0003.0'\n", 'RED'); }
      if($this->validateDecimal('3.000') !== TRUE){ cecho("Validator testing issue: xsd:decimal with '3.000'\n", 'RED'); }
      if($this->validateDecimal('3,5') !== FALSE){ cecho("Validator testing issue: xsd:decimal with '3,5'\n", 'RED'); }
      
      // test xsd:double
      if($this->validateDouble('-3E2') !== TRUE){ cecho("Validator testing issue: xsd:double with '-3E2'\n", 'RED'); }
      if($this->validateDouble('4268.22752E11') !== TRUE){ cecho("Validator testing issue: xsd:double with '4268.22752E11'\n", 'RED'); }
      if($this->validateDouble('+24.3e-3') !== TRUE){ cecho("Validator testing issue: xsd:double with '+24.3e-3'\n", 'RED'); }
      if($this->validateDouble('12') !== TRUE){ cecho("Validator testing issue: xsd:double with '12'\n", 'RED'); }
      if($this->validateDouble('+3.5') !== TRUE){ cecho("Validator testing issue: xsd:double with '+3.5'\n", 'RED'); }
      if($this->validateDouble('-INF') !== TRUE){ cecho("Validator testing issue: xsd:double with '-INF'\n", 'RED'); }
      if($this->validateDouble('-0') !== TRUE){ cecho("Validator testing issue: xsd:double with '-0'\n", 'RED'); }
      if($this->validateDouble('NaN') !== TRUE){ cecho("Validator testing issue: xsd:double with 'NaN'\n", 'RED'); }
      if($this->validateDouble('-3E2.4') !== FALSE){ cecho("Validator testing issue: xsd:double with '-3E2.4'\n", 'RED'); }
      if($this->validateDouble('12E') !== FALSE){ cecho("Validator testing issue: xsd:double with '12E'\n", 'RED'); }
      if($this->validateDouble('NAN') !== FALSE){ cecho("Validator testing issue: xsd:double with 'NAN'\n", 'RED'); }
      
      // test xsd:float
      if($this->validateFloat('-3E2') !== TRUE){ cecho("Validator testing issue: xsd:float with '-3E2'\n", 'RED'); }
      if($this->validateFloat('4268.22752E11') !== TRUE){ cecho("Validator testing issue: xsd:float with '4268.22752E11'\n", 'RED'); }
      if($this->validateFloat('+24.3e-3') !== TRUE){ cecho("Validator testing issue: xsd:float with '+24.3e-3'\n", 'RED'); }
      if($this->validateFloat('12') !== TRUE){ cecho("Validator testing issue: xsd:float with '12'\n", 'RED'); }
      if($this->validateFloat('+3.5') !== TRUE){ cecho("Validator testing issue: xsd:float with '+3.5'\n", 'RED'); }
      if($this->validateFloat('-INF') !== TRUE){ cecho("Validator testing issue: xsd:float with '-INF'\n", 'RED'); }
      if($this->validateFloat('-0') !== TRUE){ cecho("Validator testing issue: xsd:float with '-0'\n", 'RED'); }
      if($this->validateFloat('NaN') !== TRUE){ cecho("Validator testing issue: xsd:float with 'NaN'\n", 'RED'); }
      if($this->validateFloat('-3E2.4') !== FALSE){ cecho("Validator testing issue: xsd:float with '-3E2.4'\n", 'RED'); }
      if($this->validateFloat('12E') !== FALSE){ cecho("Validator testing issue: xsd:float with '12E'\n", 'RED'); }
      if($this->validateFloat('NAN') !== FALSE){ cecho("Validator testing issue: xsd:float with 'NAN'\n", 'RED'); }

      // test xsd:int
      if($this->validateInt('+3') !== TRUE){ cecho("Validator testing issue: xsd:int with '+3'\n", 'RED'); }
      if($this->validateInt('122') !== TRUE){ cecho("Validator testing issue: xsd:int with '122'\n", 'RED'); }
      if($this->validateInt('0') !== TRUE){ cecho("Validator testing issue: xsd:int with '0'\n", 'RED'); }
      if($this->validateInt('-12312') !== TRUE){ cecho("Validator testing issue: xsd:int with '-12312'\n", 'RED'); }
      if($this->validateInt('2147483650') !== FALSE){ cecho("Validator testing issue: xsd:int with '2147483650'\n", 'RED'); }
      if($this->validateInt('-2147483650') !== FALSE){ cecho("Validator testing issue: xsd:int with '-2147483650'\n", 'RED'); }
      if($this->validateInt('3.0') !== FALSE){ cecho("Validator testing issue: xsd:int with '3.0'\n", 'RED'); }

      // test xsd:integer
      if($this->validateInteger('+3') !== TRUE){ cecho("Validator testing issue: xsd:integer with '+3'\n", 'RED'); }
      if($this->validateInteger('122') !== TRUE){ cecho("Validator testing issue: xsd:integer with '122'\n", 'RED'); }
      if($this->validateInteger('0') !== TRUE){ cecho("Validator testing issue: xsd:integer with '0'\n", 'RED'); }
      if($this->validateInteger('-12312') !== TRUE){ cecho("Validator testing issue: xsd:integer with '-12312'\n", 'RED'); }
      if($this->validateInteger('2147483650') !== TRUE){ cecho("Validator testing issue: xsd:integer with '2147483650'\n", 'RED'); }
      if($this->validateInteger('-2147483650') !== TRUE){ cecho("Validator testing issue: xsd:integer with '-2147483650'\n", 'RED'); }
      if($this->validateInteger('3.0') !== FALSE){ cecho("Validator testing issue: xsd:integer with '3.0'\n", 'RED'); }

      // test xsd:nonNegativeInteger
      if($this->validateNonNegativeInteger('+3') !== TRUE){ cecho("Validator testing issue: xsd:nonNegativeInteger with '+3'\n", 'RED'); }
      if($this->validateNonNegativeInteger('122') !== TRUE){ cecho("Validator testing issue: xsd:nonNegativeInteger with '122'\n", 'RED'); }
      if($this->validateNonNegativeInteger('0') !== TRUE){ cecho("Validator testing issue: xsd:nonNegativeInteger with '0'\n", 'RED'); }
      if($this->validateNonNegativeInteger('-3') !== FALSE){ cecho("Validator testing issue: xsd:nonNegativeInteger with '-3'\n", 'RED'); }
      if($this->validateNonNegativeInteger('3.0') !== FALSE){ cecho("Validator testing issue: xsd:nonNegativeInteger with '3.0'\n", 'RED'); }

      // test xsd:nonPositiveInteger
      if($this->validateNonPositiveInteger('-3') !== TRUE){ cecho("Validator testing issue: xsd:nonPositiveInteger with '-3'\n", 'RED'); }
      if($this->validateNonPositiveInteger('0') !== TRUE){ cecho("Validator testing issue: xsd:nonPositiveInteger with '0'\n", 'RED'); }
      if($this->validateNonPositiveInteger('3') !== FALSE){ cecho("Validator testing issue: xsd:nonPositiveInteger with '3'\n", 'RED'); }
      if($this->validateNonPositiveInteger('3.0') !== FALSE){ cecho("Validator testing issue: xsd:nonPositiveInteger with '3.0'\n", 'RED'); }

      // test xsd:positiveInteger
      if($this->validatePositiveInteger('+3') !== TRUE){ cecho("Validator testing issue: xsd:positiveInteger with '+3'\n", 'RED'); }
      if($this->validatePositiveInteger('122') !== TRUE){ cecho("Validator testing issue: xsd:positiveInteger with '122'\n", 'RED'); }
      if($this->validatePositiveInteger('1') !== TRUE){ cecho("Validator testing issue: xsd:positiveInteger with '1'\n", 'RED'); }
      if($this->validatePositiveInteger('0') !== FALSE){ cecho("Validator testing issue: xsd:positiveInteger with '0'\n", 'RED'); }
      if($this->validatePositiveInteger('-3') !== FALSE){ cecho("Validator testing issue: xsd:positiveInteger with '-3'\n", 'RED'); }
      if($this->validatePositiveInteger('3.0') !== FALSE){ cecho("Validator testing issue: xsd:positiveInteger with '3.0'\n", 'RED'); }

      // test xsd:negativeInteger
      if($this->validateNegativeInteger('-3') !== TRUE){ cecho("Validator testing issue: xsd:negativeInteger with '-3'\n", 'RED'); }
      if($this->validateNegativeInteger('-1') !== TRUE){ cecho("Validator testing issue: xsd:negativeInteger with '-1'\n", 'RED'); }
      if($this->validateNegativeInteger('0') !== FALSE){ cecho("Validator testing issue: xsd:negativeInteger with '0'\n", 'RED'); }
      if($this->validateNegativeInteger('3') !== FALSE){ cecho("Validator testing issue: xsd:negativeInteger with '3'\n", 'RED'); }
      if($this->validateNegativeInteger('3.0') !== FALSE){ cecho("Validator testing issue: xsd:negativeInteger with '3.0'\n", 'RED'); }

      // test xsd:short
      if($this->validateShort('+3') !== TRUE){ cecho("Validator testing issue: xsd:short with '+3'\n", 'RED'); }
      if($this->validateShort('122') !== TRUE){ cecho("Validator testing issue: xsd:short with '122'\n", 'RED'); }
      if($this->validateShort('0') !== TRUE){ cecho("Validator testing issue: xsd:short with '0'\n", 'RED'); }
      if($this->validateShort('-1213') !== TRUE){ cecho("Validator testing issue: xsd:short with '-1213'\n", 'RED'); }
      if($this->validateShort('32770') !== FALSE){ cecho("Validator testing issue: xsd:short with '32770'\n", 'RED'); }
      if($this->validateShort('-32770') !== FALSE){ cecho("Validator testing issue: xsd:short with '-32770'\n", 'RED'); }
      if($this->validateShort('3.0') !== FALSE){ cecho("Validator testing issue: xsd:short with '3.0'\n", 'RED'); }

      // test xsd:unsignedShort
      if($this->validateUnsignedShort('+3') !== TRUE){ cecho("Validator testing issue: xsd:unsignedShort with '+3'\n", 'RED'); }
      if($this->validateUnsignedShort('122') !== TRUE){ cecho("Validator testing issue: xsd:unsignedShort with '122'\n", 'RED'); }
      if($this->validateUnsignedShort('0') !== TRUE){ cecho("Validator testing issue: xsd:unsignedShort with '0'\n", 'RED'); }
      if($this->validateUnsignedShort('-121') !== FALSE){ cecho("Validator testing issue: xsd:unsignedShort with '-121'\n", 'RED'); }
      if($this->validateUnsignedShort('65540') !== FALSE){ cecho("Validator testing issue: xsd:unsignedShort with '65540'\n", 'RED'); }
      if($this->validateUnsignedShort('3.0') !== FALSE){ cecho("Validator testing issue: xsd:unsignedShort with '3.0'\n", 'RED'); }

      // test xsd:long
      if($this->validateLong('+3') !== TRUE){ cecho("Validator testing issue: xsd:long with '+3'\n", 'RED'); }
      if($this->validateLong('122') !== TRUE){ cecho("Validator testing issue: xsd:long with '122'\n", 'RED'); }
      if($this->validateLong('0') !== TRUE){ cecho("Validator testing issue: xsd:long with '0'\n", 'RED'); }
      if($this->validateLong('-1231235555') !== TRUE){ cecho("Validator testing issue: xsd:long with '-1231235555'\n", 'RED'); }
      if($this->validateLong('9223372036854775810') !== FALSE){ cecho("Validator testing issue: xsd:long with '9223372036854775810'\n", 'RED'); }
      if($this->validateLong('-9223372036854775810') !== FALSE){ cecho("Validator testing issue: xsd:long with '-9223372036854775810'\n", 'RED'); }
      if($this->validateLong('3.0') !== FALSE){ cecho("Validator testing issue: xsd:long with '3.0'\n", 'RED'); }

      // test xsd:unsignedLong
      if($this->validateUnsignedLong('+3') !== TRUE){ cecho("Validator testing issue: xsd:unsignedLong with '+3'\n", 'RED'); }
      if($this->validateUnsignedLong('122') !== TRUE){ cecho("Validator testing issue: xsd:unsignedLong with '122'\n", 'RED'); }
      if($this->validateUnsignedLong('0') !== TRUE){ cecho("Validator testing issue: xsd:unsignedLong with '0'\n", 'RED'); }
      if($this->validateUnsignedLong('-123') !== FALSE){ cecho("Validator testing issue: xsd:unsignedLong with '-123'\n", 'RED'); }
      if($this->validateUnsignedLong('18446744073709551620') !== FALSE){ cecho("Validator testing issue: xsd:unsignedLong with '18446744073709551620'\n", 'RED'); }
      if($this->validateUnsignedLong('3.0') !== FALSE){ cecho("Validator testing issue: xsd:unsignedLong with '3.0'\n", 'RED'); }

      // test xsd:hexBinary
      if($this->validateHexBinary('0FB8') !== TRUE){ cecho("Validator testing issue: xsd:hexBinary with '0FB8'\n", 'RED'); }
      if($this->validateHexBinary('0fb8') !== TRUE){ cecho("Validator testing issue: xsd:hexBinary with '0fb8'\n", 'RED'); }
      if($this->validateHexBinary('FB8') !== FALSE){ cecho("Validator testing issue: xsd:hexBinary with 'FB8'\n", 'RED'); }
      if($this->validateHexBinary('0G') !== FALSE){ cecho("Validator testing issue: xsd:hexBinary with '0G'\n", 'RED'); }

      // test xsd:language
      if($this->validateLanguage('en') !== TRUE){ cecho("Validator testing issue: xsd:language with 'en'\n", 'RED'); }
      if($this->validateLanguage('en-GB') !== TRUE){ cecho("Validator testing issue: xsd:language with 'en-GB'\n", 'RED'); }
      if($this->validateLanguage('fr') !== TRUE){ cecho("Validator testing issue: xsd:language with 'fr'\n", 'RED'); }
      if($this->validateLanguage('de') !== TRUE){ cecho("Validator testing issue: xsd:language with 'de'\n", 'RED'); }
      if($this->validateLanguage('i-navajo') !== TRUE){ cecho("Validator testing issue: xsd:language with 'i-navajo'\n", 'RED'); }
      if($this->validateLanguage('x-Newspeak') !== TRUE){ cecho("Validator testing issue: xsd:language with 'x-Newspeak'\n", 'RED'); }
      if($this->validateLanguage('longerThan8') !== FALSE){ cecho("Validator testing issue: xsd:language with 'longerThan8'\n", 'RED'); }

      // test xsd:Name
      if($this->validateName('myElement') !== TRUE){ cecho("Validator testing issue: xsd:Name with 'myElement'\n", 'RED'); }
      if($this->validateName('_my.Element') !== TRUE){ cecho("Validator testing issue: xsd:Name with '_my.Element'\n", 'RED'); }
      if($this->validateName('my-element') !== TRUE){ cecho("Validator testing issue: xsd:Name with 'my-element'\n", 'RED'); }
      if($this->validateName('pre:myelement3') !== TRUE){ cecho("Validator testing issue: xsd:Name with 'pre:myelement3'\n", 'RED'); }
      if($this->validateName('-myelement') !== FALSE){ cecho("Validator testing issue: xsd:Name with '-myelement'\n", 'RED'); }
      if($this->validateName('3rdElement') !== FALSE){ cecho("Validator testing issue: xsd:Name with '3rdElement'\n", 'RED'); }

      // test xsd:NCName
      if($this->validateNCName('myElement') !== TRUE){ cecho("Validator testing issue: xsd:NCName with 'myElement'\n", 'RED'); }
      if($this->validateNCName('_my.Element') !== TRUE){ cecho("Validator testing issue: xsd:NCName with '_my.Element'\n", 'RED'); }
      if($this->validateNCName('my-element') !== TRUE){ cecho("Validator testing issue: xsd:NCName with 'my-element'\n", 'RED'); }
      if($this->validateNCName('pre:myelement3') !== FALSE){ cecho("Validator testing issue: xsd:NCName with 'pre:myelement3'\n", 'RED'); }
      if($this->validateNCName('-myelement') !== FALSE){ cecho("Validator testing issue: xsd:NCName with '-myelement'\n", 'RED'); }
      if($this->validateNCName('3rdElement') !== FALSE){ cecho("Validator testing issue: xsd:NCName with '3rdElement'\n", 'RED'); }

      // test xsd:NMTOKEN
      if($this->validateNMTOKEN('ABCD') !== TRUE){ cecho("Validator testing issue: xsd:NMTOKEN with 'ABCD'\n", 'RED'); }
      if($this->validateNMTOKEN('123_456') !== TRUE){ cecho("Validator testing issue: xsd:NMTOKEN with '123_456'\n", 'RED'); }
      if($this->validateNMTOKEN('  starts_with_a_space') !== TRUE){ cecho("Validator testing issue: xsd:NMTOKEN with '  starts_with_a_space'\n", 'RED'); }
      if($this->validateNMTOKEN('contains a space') !== FALSE){ cecho("Validator testing issue: xsd:NMTOKEN with 'contains a space'\n", 'RED'); }
      if($this->validateNMTOKEN('') !== FALSE){ cecho("Validator testing issue: xsd:NMTOKEN with ''\n", 'RED'); }

      // test xsd:string
      if($this->validateString('This is a string!') !== TRUE){ cecho("Validator testing issue: xsd:string with 'This is a string!'\n", 'RED'); }
      if($this->validateString('12.5') !== TRUE){ cecho("Validator testing issue: xsd:string with '12.5'\n", 'RED'); }
      if($this->validateString('') !== TRUE){ cecho("Validator testing issue: xsd:string with ''\n", 'RED'); }
      if($this->validateString('PB&amp;J') !== TRUE){ cecho("Validator testing issue: xsd:string with 'PB&amp;J'\n", 'RED'); }
      if($this->validateString('   Separated   by   3   spaces.') !== TRUE){ cecho("Validator testing issue: xsd:string with '   Separated   by   3   spaces.'\n", 'RED'); }
      if($this->validateString("This\nis on two lines.") !== TRUE){ cecho("Validator testing issue: xsd:string with 'This\nis on two lines.'\n", 'RED'); }
      if($this->validateString('AT&T') !== FALSE){ cecho("Validator testing issue: xsd:string with 'AT&T'\n", 'RED'); }
      if($this->validateString('3 < 4') !== FALSE){ cecho("Validator testing issue: xsd:string with '3 < 4'\n", 'RED'); }

      // test rdf:XMLLiteral
      if($this->validateXMLLiteral('This is a string!') !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with 'This is a string!'\n", 'RED'); }
      if($this->validateXMLLiteral('12.5') !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with '12.5'\n", 'RED'); }
      if($this->validateXMLLiteral('') !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with ''\n", 'RED'); }
      if($this->validateXMLLiteral('PB&amp;J') !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with 'PB&amp;J'\n", 'RED'); }
      if($this->validateXMLLiteral('   Separated   by   3   spaces.') !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with '   Separated   by   3   spaces.'\n", 'RED'); }
      if($this->validateXMLLiteral("This\nis on two lines.") !== TRUE){ cecho("Validator testing issue: rdf:XMLLiteral with 'This\nis on two lines.'\n", 'RED'); }
      if($this->validateXMLLiteral('AT&T') !== FALSE){ cecho("Validator testing issue: rdf:XMLLiteral with 'AT&T'\n", 'RED'); }
      if($this->validateXMLLiteral('3 < 4') !== FALSE){ cecho("Validator testing issue: rdf:XMLLiteral with '3 < 4'\n", 'RED'); }

      // test xsd:token
      if($this->validateToken('This is a string!') !== TRUE){ cecho("Validator testing issue: xsd:token with 'This is a string!'\n", 'RED'); }
      if($this->validateToken('12.5') !== TRUE){ cecho("Validator testing issue: xsd:token with '12.5'\n", 'RED'); }
      if($this->validateToken('') !== TRUE){ cecho("Validator testing issue: xsd:token with ''\n", 'RED'); }
      if($this->validateToken('PB&amp;J') !== TRUE){ cecho("Validator testing issue: xsd:token with 'PB&amp;J'\n", 'RED'); }
      if($this->validateToken('   Separated   by   3   spaces.') !== TRUE){ cecho("Validator testing issue: xsd:token with '   Separated   by   3   spaces.'\n", 'RED'); }
      if($this->validateToken("This\nis on two lines.") !== TRUE){ cecho("Validator testing issue: xsd:token with 'This\nis on two lines.'\n", 'RED'); }
      if($this->validateToken('AT&T') !== FALSE){ cecho("Validator testing issue: xsd:token with 'AT&T'\n", 'RED'); }
      if($this->validateToken('3 < 4') !== FALSE){ cecho("Validator testing issue: xsd:token with '3 < 4'\n", 'RED'); }

      // test xsd:normalizedString
      if($this->validateNormalizedString('This is a string!') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'This is a string!'\n", 'RED'); }
      if($this->validateNormalizedString('12.5') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with '12.5'\n", 'RED'); }
      if($this->validateNormalizedString('') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with ''\n", 'RED'); }
      if($this->validateNormalizedString('PB&amp;J') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'PB&amp;J'\n", 'RED'); }
      if($this->validateNormalizedString('   Separated   by   3   spaces.') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with '   Separated   by   3   spaces.'\n", 'RED'); }
      if($this->validateNormalizedString("This\nis on two lines.") !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'This\nis on two lines.'\n", 'RED'); }
      if($this->validateNormalizedString('AT&T') !== FALSE){ cecho("Validator testing issue: xsd:normalizedString with 'AT&T'\n", 'RED'); }
      if($this->validateNormalizedString('3 < 4') !== FALSE){ cecho("Validator testing issue: xsd:normalizedString with '3 < 4'\n", 'RED'); }

      // test rdf:PlainLiteral
      if($this->validatePlainLiteral('Family Guy@en') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@en'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy@EN') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@EN'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy@FOX@en') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@FOX@en'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy@') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy@FOX@') !== TRUE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@FOX@'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy') !== FALSE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy'\n", 'RED'); }
      if($this->validatePlainLiteral('Family Guy@12') !== FALSE){ cecho("Validator testing issue: xsd:normalizedString with 'Family Guy@12'\n", 'RED'); }
      
    }
  }
?>
