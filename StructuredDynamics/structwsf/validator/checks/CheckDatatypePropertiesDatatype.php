<?php

  namespace StructuredDynamics\structwsf\validator\checks; 

  use \StructuredDynamics\structwsf\php\api\ws\sparql\SparqlQuery;
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
                    if(!(($range == 'http://www.w3.org/2001/XMLSchema#boolean' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#unsignedByte' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#nonPositiveInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#positiveInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#negativeInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#unsignedLong' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#nonNegativeInteger' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#unsignedShort' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#integer') ||
                         ($range == 'http://www.w3.org/2001/XMLSchema#unsignedLong' && $value['type'] == 'http://www.w3.org/2001/XMLSchema#decimal')))
                    {
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
                  }

                  // If then match, then we make sure that the value is valid according to the
                  // internal Check datatype validation tests

                  $datatypeValidationError = FALSE;
                  
                  switch($range)
                  {
                    case "http://www.w3.org/2001/XMLSchema#anySimpleType":
                      if(!$this->validateAnySimpleType($value['value']))
                      {
                        $datatypeValidationError = TRUE;
                      }
                    break;
                    
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
                      // Custom type, try to validate it according to the 
                      // description of that custom datatype within the 
                      // ontology
                      if(!$this->validateCustomDatatype($value['type'], $value['value']))
                      {
                        $datatypeValidationError = TRUE;
                      }
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
                      'valueDatatype' => $value['type'],
                      'invalidValue' => $value['value'],
                      'affectedRecord' => $value['affectedRecord']
                    );                                                  
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

          if(isset($error['invalidValue']) && !empty($error['invalidValue']))
          {
            $xml .= "        <invalidValue>".$error['invalidValue']."</invalidValue>\n";
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
          
          if(isset($error['invalidValue']) && !empty($error['invalidValue']))
          {
            $json .= "        \"invalidValue\": \"".$error['invalidValue']."\",\n";
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
  }
?>
