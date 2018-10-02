<?php
/*************************************************************************************************
 * Copyright 2014 JPL TSolucio, S.L. -- This file is a part of TSOLUCIO coreBOS customizations.
 * You can copy, adapt and distribute the work under the "Attribution-NonCommercial-ShareAlike"
 * Vizsage Public License (the "License"). You may not use this file except in compliance with the
 * License. Roughly speaking, non-commercial users may share and modify this code, but must give credit
 * and share improvements. However, for proper details please read the full License, available at
 * http://vizsage.com/license/Vizsage-License-BY-NC-SA.html and the handy reference for understanding
 * the full license at http://vizsage.com/license/Vizsage-Deed-BY-NC-SA.html. Unless required by
 * applicable law or agreed to in writing, any software distributed under the License is distributed
 * on an  "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and limitations under the
 * License terms of Creative Commons Attribution-NonCommercial-ShareAlike 3.0 (the License).
 *************************************************************************************************
 *  Module       : coreBOSwsWebform
 *  Version      : 1.0
 *  Author       : JPL TSolucio, S. L.
 *************************************************************************************************/

require_once('cbwsclib/WSClient.php');

/**
 *
 * REST client to convert form data to entities
 *
 * Use:
 *
 * $config = array(
 *     // Connection parameters
 *     'url' => 'http://localhost/corebos/',
 *     'user' => 'admin',
 *     'password' => '',
 *     // Entity mapping to process form fields
 *     'map' => array(
 *         // WebServices module name
 *         'Contacts' => array(
 *             // Field mapping
 *             'fields' => array(
 *                 // [Entity field name] => [form field name]
 *                 'firstname' => 'firstname',
 *                 // Use just the field name if both are the same
 *                 'lastname',
 *                 'email',
 *             ),
 *             // Default values for creation of new records
 *             'defaults' => array(
 *                 'cf_1306' => '1',
 *             ),
 *             // Fields that should match to find a duplicate
 *             'matching' => array(
 *                 // we can specify an 'and' or 'or' key to group fields inside parenthesis with the given operator, nesting is possible
 *                 'or' => array(
 *                     'and' => array(
 *                         // [field name in the dabase] => [field name in the new entity]
 *                         'firstname' => 'firstname',
 *                         // When both are the same we can write just one
 *                         'lastname',
 *                     ),
 *                     'email',
 *                 ),
 *                 // The resulting condition is ( ( firstname=e.firstname and lastname=e.lastname ) or email=e.email )
 *             ),
 *             // Optionally, an entity can include other related entities
 *             'has' => array(
 *                 // We repeat here the structure for an Entity mapping
 *                 'Potentials' => array(
 *                     'fields' => array(
 *                         'related_to' => '[Contacts]',
 *                         'potentialname' => 'potential_name',
 *                         'closingdate' => 'potential_closingdate',
 *                         'sales_stage' => 'potential_sales_stage',
 *                     ),
 *                     // Default values for creation of new records
 *                     'matching' => array(
 *                         // When no operator is specified, 'and' is used, so here all fields should match
 *                         'related_to',
 *                         'potentialname',
 *                     ),
 *                 ),
 *                 // Relate new record with this campaign (or whatever)
 *                 'Campaigns' => array(
 *                     'relatewith' => array(
 *                         'crmid' => '1x999',
 *                     ),
 *                 ),
 *             ),
 *         ),
 *         // We could go on with other entity mappings
 *     ),
 * );
 * $webform = new WsWebForm($config);
 * $webform->send($_REQUEST);
 *
 */
class WsWebform
{

    public $client;

    public $url;

    public $user;

    public $password;

    public $entities = array();
    public $entityIDs = array();

    public $data;

    public $update;

    /**
     * Constructor
     */
    public function __construct($config)
    {
        $this->url = $config['url'];
        $this->user = $config['user'];
        $this->password = $config['password'];
        $this->entities = $this->parseEntityData($config['map']);
        $this->client = new Vtiger_WSClient($this->url);
        if (!$this->client->doLogin($this->user, $this->password)) {
            throw new Exception("Login error");
        }
    }

    /**
     * Send a form data to WebServices
     */
    public function send($data, $update = true)
    {
        $this->data = $data;
        $this->update = $update;
        return $this->sendEntities($this->entities);
    }

    /**
     * Parses entity configuration data
     */
    public function parseEntityData($data)
    {
        $entities = array();
        foreach ($data as $entityName => $entityConfig) {
            $entity = array();
            $entity['name'] = $entityName;
            if (isset($entityConfig['fields'])) {
                $entity['fields'] = $this->fillArrayKeys($entityConfig['fields']);
            }
            if (isset($entityConfig['defaults'])) {
                $entity['defaults'] = $this->fillArrayKeys($entityConfig['defaults']);
            }
            if (isset($entityConfig['matching'])) {
                $entity['matching'] = $this->fillArrayKeys($entityConfig['matching']);
            }
            if (isset($entityConfig['has'])) {
                $entity['has'] = $this->parseEntityData($entityConfig['has']);
            }
            if (isset($entityConfig['relatewith'])) {
                $entity['relatewith'] = $entityConfig['relatewith']['crmid'];
            }
            $entities[] = $entity;
        }
        return $entities;
    }

    /**
     *  Changes numeric keys by their stored value, to make config arrays simpler
     */
    public function fillArrayKeys($array)
    {
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->fillArrayKeys($value);
            } elseif (is_numeric($key)) {
                $key = $value;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Renders a query condition from the matching field in the configuration data
     */
    public function renderCondition($entity, $matching = null, $operator = 'and')
    {
        if (is_null($matching)) {
            $matching = $entity['matching'];
        }
        if ($operator != 'or' && $operator != 'and') {
            throw new Exception("Bad operator '{$operator}' in matching conditions");
        }
        $tests = array();
        foreach ($matching as $key => $value) {
            if (is_array($value)) {
                $tests[] = $this->renderCondition($entity, $value, $key);
            } else {
                $realValue = addslashes($entity['data'][$value]);
                $tests[] = $key . "='{$realValue}'";
            }
        }
        $condition = implode(' ' . $operator . ' ', $tests);
        return $condition;
    }

    /**
     * Gets an array with the data for an entity
     */
    public function getEntityData($entity)
    {
        $data = array();
        foreach ($entity['fields'] as  $entityField => $formField) {
            if (preg_match('/\[(.*)\]/', $formField, $matches) && isset($this->parentIds[$matches[1]])) {
                $data[$entityField] = $this->parentIds[$matches[1]];
                continue;
            }
            if (!isset($this->data[$formField])) {
                throw new Exception("Field '{$formField}' doesn't exist.");
            }
            $data[$entityField] = $this->data[$formField];
        }
        return $data;
    }

    /**
     * Checks an entity existing with the matching fields and returns the id
     */
    public function exists($entity)
    {
        $condition = $this->renderCondition($entity);
        $query = "select id from {$entity['name']} where {$condition};";
        $data = $this->client->doQuery($query);
        if (empty($data)) {
            return false;
        }
        return $data[0]['id'];
    }

    /**
     * Create an entity with provided form data
     */
    public function create($entity)
    {
        if (isset($entity['defaults'])) {
            foreach ($entity['defaults'] as $field => $value) {
                if (!isset($entity['data'][$field])) $entity['data'][$field] = $value;
            }
        }
        return $this->client->doCreate($entity['name'], $entity['data']);
    }

    /**
     * Update an entity with provided form data
     */
    public function update($entity, $id)
    {
        if (!method_exists($this->client, 'doUpdate')) {
            return false;
        }
        $entityData = $this->client->doRetrieve($id);
        unset($entityData['assigned_user_id']);
        $entityData = array_merge($entityData, $entity['data']);
        return $this->client->doUpdate($entity['name'], $entityData);
    }

    /**
     * Update an entity with provided form data
     */
    public function setrelation($relate_this_id, $with_these_ids)
    {
        if (!method_exists($this->client, 'doSetRelated')) {
            return false;
        }
        return $this->client->doSetRelated($relate_this_id, $with_these_ids);
    }

    /**
     * Sends all entities configured
     */
    public function sendEntities($entities)
    {
        foreach ($entities as $entity) {
            if (isset($entity['fields'])) {
                if (!$this->sendEntity($entity)) {
                    return false;
                }
            } elseif (isset($entity['relatewith'])) {
                if (!$this->setrelation(reset($this->parentIds),$entity['relatewith'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Process an entity to be created/updated thru REST
     */
    public function sendEntity($entity)
    {
        $entity['data'] = $this->getEntityData($entity);
        if (isset($entity['matching'])) {
            $id = $this->exists($entity);
        } else {
            $id = null;
        }
        if ($id) {
            if ($this->update) {
                $record = $this->update($entity, $id);
            } else {
                return false;
            }
        } else {
            $record = $this->create($entity);
            $id = $record['id'];
        }
        $this->entityIDs[$entity['name']][] = $id;
        if ($record && isset($entity['has'])) {
            $this->parentIds[$entity['name']] = $id;
            $return = $this->sendEntities($entity['has']);
            unset($this->parentIds[$entity['name']]);
            return $return;
        }
        return !empty($record);
    }

    public function lastError() {
        return $this->client->lastError();
    }

}

