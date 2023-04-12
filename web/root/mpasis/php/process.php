<?php E_STRICT;
session_start();

class ajaxResponse implements JsonSerializable
{
	private $type; // possible values: "Success", "Info", "Error", "Text", "Username", "JSON", "DataRows", "DataRow", "User", "Entries", "Entry"
	private $content;
	
	public function __construct($type, $content)
	{
		$this->type = $type;
		$this->content = $content;
	}
	
	public function get_type()
	{
		return $this->type;
	}
	
	public function get_content()
	{
		return $this->content;
	}
	
	public function to_array()
	{
        return array(
            "type"=>$this->type,
            "content"=>$this->content
        );
	}
	
	// override to allow json_encode() to convert an instance of this class
	public function jsonSerialize ()
	{ 
		return $this->to_array();
    }
};
require_once('../../path.php');

require_once(__FILE_ROOT__ . '/php/classes/db.php');
require_once(__FILE_ROOT__ . '/php/secure/dbcreds.php');
require_once(__FILE_ROOT__ . '/php/audit/log.php');

function sendDebug($data)
{
    echo(json_encode(new ajaxResponse('Debug', json_encode($data))));
    exit;
}

// TEST ONLY !!!!!!!!!!!!!
if (isset($_REQUEST['test']))
{
    echo(json_encode(new ajaxResponse('Info','test reply')));
    return;
}
// TEST ONLY !!!!!!!!!!!!!

if (isset($_SESSION['user']))
{
    if ($_REQUEST['q'] == 'login') // UNUSED
    {
        echo json_encode(new ajaxResponse('User', json_encode(array('Username'=>$_SESSION['user'], 'UserId'=>1 * $_SESSION['user_id']))));
        return;
	}
    elseif ($_REQUEST['a'] == 'logout') // UNUSED
    {
        session_unset();
		session_destroy();
		echo json_encode(new ajaxResponse('Success', 'Signed out.'));
        return;
    }
    elseif (isset($_REQUEST['a']))
    {
        $dbconn = new DatabaseConnection($dbtype, $servername, $dbuser, $dbpass, $dbname, []);

        switch($_REQUEST['a'])
        {
            case 'fetch':
                switch($_REQUEST['f'])
                {
                    case 'tempuser':
                        $dbResults = $dbconn->executeQuery(
                            'SELECT 
                                given_name,
                                middle_name,
                                family_name,
                                spouse_name,
                                ext_name,
                                username,
                                sergs_access_level,
                                opms_access_level,
                                mpasis_access_level
                            FROM SDOStoTomas.Person
                            INNER JOIN SDOStoTomas.Temp_User
                            ON Person.personId=Temp_User.personId' . (isset($_REQUEST['k']) && trim($_REQUEST['k']) == 'all' ? '' : ' WHERE Temp_User.username LIKE "' . trim($_REQUEST['k']) . '";')
                        );
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'searchApplicationByName':
                        $dbResults = $dbconn->executeQuery(
                            'SELECT
                                *
                            FROM SDOStoTomas.Person
                            INNER JOIN SDOStoTomas.Job_Application
                            ON Person.personId=Job_Application.personId
                            WHERE Person.given_name LIKE "%' . $_REQUEST['name'] . '%" OR Person.family_name LIKE "%' . $_REQUEST['name'] . '%" OR Person.spouse_name LIKE "%' . $_REQUEST['name'] . '%"'
                        );
                        $results = [];

                        foreach ($dbResults as $dbResult)
                        {
                            $results[$dbResult['application_code']] = $dbResult;
                        }
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($results))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'applicant-data-entry-initial':
                        $positions = $dbconn->select('Position', '*', '');

                        if (is_null($dbconn->lastException))
                        {
                            $requiredEligibilities = $dbconn->executeQuery(
                                'SELECT
                                    required_eligibilityId,
                                    plantilla_item_number,
                                    re2.eligibilityId,
                                    re2.eligibility,
                                    eligibilityId2,
                                    eligibility2,
                                    eligibilityId3,
                                    e3.eligibility as eligibility3
                                FROM
                                    (SELECT
                                        required_eligibilityId,
                                        plantilla_item_number,
                                        re.eligibilityId,
                                        re.eligibility,
                                        eligibilityId2,
                                        e2.eligibility as eligibility2,
                                        eligibilityId3
                                    FROM 
                                        (SELECT
                                            required_eligibilityId,
                                            plantilla_item_number,
                                            Required_Eligibility.eligibilityId,
                                            eligibility,
                                            eligibilityId2,
                                            eligibilityId3
                                        FROM Required_Eligibility
                                        INNER JOIN Eligibility ON Required_Eligibility.eligibilityId = Eligibility.eligibilityId) re
                                    LEFT JOIN Eligibility e2 ON re.eligibilityId2 = e2.eligibilityId) re2
                                LEFT JOIN Eligibility e3 on re2.eligibilityId3 = e3.eligibilityId
                                ;'
                            );

                            if (is_null($dbconn->lastException))
                            {
                                for ($i = 0; $i < count($positions); $i++) {
                                    $positions[$i]['required_eligibility'] = [];

                                    foreach ($requiredEligibilities as $requiredEligibility)
                                    {
                                        if ($requiredEligibility['plantilla_item_number'] == $positions[$i]['plantilla_item_number'])
                                        {
                                            array_push($positions[$i]['required_eligibility'], $requiredEligibility);
                                        }
                                    }
                                }

                                $educIncrementTable = $dbconn->select('MPS_Increment_Table_Education', '*', '');

                                if (is_null($dbconn->lastException))
                                {
                                    echo(json_encode(new ajaxResponse('Data', json_encode(['positions'=>$positions, 'mps_increment_table_education'=>$educIncrementTable]))));
                                }
                                else
                                {
                                    echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                                }
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                            }
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'positions':
                        $dbResults = $dbconn->select('Position', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'positionTitles':
                        $dbResults = $dbconn->select('Position', 'position_title', 'GROUP BY position_title');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'parenTitles':
                        $where = (isset($_REQUEST['positionTitle']) ? 'WHERE position_title="' . $_REQUEST['positionTitle'] . '"' : '');

                        $dbResults = $dbconn->select('Position', 'parenthetical_title, position_title', ($where == '' ? '' : $where));
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'plantilla':
                        $where = (isset($_REQUEST['positionTitle']) ? 'WHERE position_title="' . $_REQUEST['positionTitle'] . '"' : '');
                        $where .= (isset($_REQUEST['parenTitle']) ? ($where == '' ? 'WHERE' : 'AND') . ' parenthetical_title="' . $_REQUEST['parenTitle'] . '"' : '');

                        $dbResults = $dbconn->select('Position', 'plantilla_item_number', ($where == '' ? '' : $where));
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'positionCategory':
                        $dbResults = $dbconn->select('Position_Category', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'educLevel':
                        $dbResults = $dbconn->select('ENUM_Educational_Attainment', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'specEduc':
                        $dbResults = $dbconn->select('Specific_Education', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'civilStatus':
                        $dbResults = $dbconn->select('ENUM_Civil_Status', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'disability':
                        $dbResults = $dbconn->select('Disability', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'ethnicGroup':
                        $dbResults = $dbconn->select('Ethnicity', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'religion':
                        $dbResults = $dbconn->select('Religion', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'eligibilities':
                        $dbResults = $dbconn->select('Eligibility', '*', '');
    
                        if (is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                        }
                        return;
                        break;
                    case 'applicantionsByApplicantOrCode':
                        $srcStr = (isset($_REQUEST['srcStr']) ? $_REQUEST['srcStr'] : "");
                        if ($srcStr == '')
                        {
                            echo(json_encode(new ajaxResponse('Info', 'Blank search string')));
                            return;
                        }

                        $dbResults = $dbconn->executeQuery(
                            "SELECT
                                *
                            FROM SDOStoTomas.Person
                            INNER JOIN SDOStoTomas.Job_Application
                            ON Job_Application.personId = Person.personId
                            WHERE given_name LIKE '%$srcStr%'
                                OR middle_name LIKE '%$srcStr%'
                                OR family_name LIKE '%$srcStr%'
                                OR spouse_name LIKE '%$srcStr%'
                                OR ext_name LIKE '%$srcStr%'
                                OR application_code LIKE '%$srcStr%'
                            LIMIT 100;
                        ");

                        if (is_null($dbconn->lastException))
                        {
                            for ($i = 0; $i < count($dbResults); $i++)
                            {
                                $dbResult = $dbResults[$i];

                                $fullName = (is_string($dbResult['spouse_name']) && $dbResult['spouse_name'] != '' ? $dbResult['spouse_name'] . ', ' : (is_string($dbResult['family_name']) && $dbResult['family_name'] != '' ? $dbResult['family_name'] . ', ' : ''));
                                $fullName .= $dbResult['given_name'];
                                $fullName .= ($fullName == '' ? '' : ' ') . (is_string($dbResult['middle_name']) && $dbResult['middle_name'] != "" ? $dbResult['middle_name'] : '');
                                $fullName = trim($fullName);
                                $fullName .= (is_string($dbResult['spouse_name']) && $dbResult['spouse_name'] != '' ? ' ' . $dbResult['family_name'] : '');
                                $fullName = trim($fullName);

                                $dbResults[$i]['applicant_name'] = $fullName;
                                $dbResults[$i]['applicant_option_label'] = $dbResult['application_code'] . " &ndash; $fullName &ndash; " . $dbResult['position_title_applied'];
                            }

                            echo(json_encode(new ajaxResponse('Data', json_encode($dbResults))));
                            return;
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                            return;
                        }
                        break;
                    default:
                        break;
                }
                break;
            case 'add':
                if (isset($_REQUEST['eligibilities']))
                {
                    $eligibilities = json_decode($_REQUEST['eligibilities'], true);
    
                    $errMsg = '';
                    $valueStr = '';
    
                    foreach ($eligibilities as $eligibility)
                    {
                        $valueStr .= ($valueStr == '' ? '' : ', ') . '("' . $eligibility['eligibility'] . '","' . $eligibility['description'] . '")';
                    }
    
                    $dbconn->insert('Eligibility', '(eligibility, description)', $valueStr);
    
                    if (is_null($dbconn->lastException))
                    {
                        // echo(json_encode(new ajaxResponse('Success', $_REQUEST['eligibilities'])));
                        echo(json_encode(new ajaxResponse('Success', 'Eligibility successfully added')));
                    }
                    else
                    {
                        echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                    }
                    
                    return;
                }
                elseif (isset($_REQUEST['specEducs'])) // THIS SECTION NEEDS TO BE MODIFIED IF EVER IT IS TO BE USED AGAIN; SPECIFIC_EDUCATION REQUIRES A JOB_APPLICATION
                {
                    $specEducs = json_decode($_REQUEST['specEducs'], true);
                    
                    $errMsg = '';
                    $valueStr = '';
                    
                    foreach ($specEducs as $specEduc)
                    {
                        
                        $valueStr .= ($valueStr == '' ? '' : ', ') . '("' . $specEduc['specific_education'] . '","' . $specEduc['description'] . '")';
                    }
    
                    $dbconn->insert('Specific_Education', '(specific_education, description)', $valueStr);
    
                    if (is_null($dbconn->lastException))
                    {
                        echo(json_encode(new ajaxResponse('Success', 'Specific course/education successfully added')));
                    }
                    else
                    {
                        echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                    }
                    
                    return;
                }
                elseif (isset($_REQUEST['positions']))
                {
                    $positions = json_decode($_REQUEST['positions'], true);

                    foreach($positions as $position)
                    {
                        $fieldStr = '';
                        $valueStr = '';

                        
                        foreach($position as $key => $value)
                        {
                            if ($key != 'required_eligibility')
                            {
                                $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                                $fieldStr .= ($fieldStr == '' ? '' : ', ') . $key;
                            }
                        }
                        $fieldStr = '(' . $fieldStr . ')';
                        $valueStr = '(' . $valueStr . ')';

                        // foreach($position['required_eligibility'] as $reqElig)
                        // {
                        //     echo($reqElig);
                        //     echo("x");
                        // }

                        $dbconn->insert('Position', $fieldStr, $valueStr);

                        if (is_null($dbconn->lastException))
                        {
                            foreach($position['required_eligibility'] as $reqElig)
                            {
                                $dbconn->insert('Required_Eligibility', '(plantilla_item_number, eligibilityId)', '("' . $position['plantilla_item_number'] . '", "' . $reqElig . '")');

                                if (!is_null($dbconn->lastException))
                                {
                                    echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                    return;        
                                }
                            }
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                            return;
                        }
                    }
                    
                    echo(json_encode(new ajaxResponse('Success', 'Successfully added Position details!')));
                    
                    return;
                }
                elseif (isset($_REQUEST['jobApplication']))
                {
                    $jobApplication = json_decode($_REQUEST['jobApplication'], true);

                    $personalInfo = $jobApplication["personalInfo"];

                    $fieldStr = '';
                    $valueStr = '';

                    $addressIds = [];

                    if (isset($personalInfo["addresses"]) && count($personalInfo["addresses"]) > 0)
                    {
                        foreach ($personalInfo["addresses"] as $address) {
                            $fieldStr = '(address)';
                            $valueStr = "('$address')";

                            $dbconn->insert('Address', $fieldStr, $valueStr);
    
                            if (is_null($dbconn->lastException))
                            {
                                array_push($addressIds, $dbconn->lastInsertId);
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }    
                        }
                    } // $addressIds[0]: present; $addressIds[1]: permanent;

                    if (isset($personalInfo["religion"]))
                    {
                        $religion = $personalInfo["religion"];
                        $fieldStr = '(religion)';
                        $valueStr = "('$religion')";

                        $dbResults = $dbconn->select('Religion', 'religionId', "WHERE religion='" . $religion . "'");

                        if (is_null($dbconn->lastException) && count($dbResults) > 0)
                        {
                            $religionId = $dbResults[0]['religionId'];
                        }
                        else
                        {
                            $dbconn->insert('Religion', $fieldStr, $valueStr);
    
                            if (is_null($dbconn->lastException))
                            {
                                $religionId = $dbconn->lastInsertId;
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }    
                        }
                    }

                    if (isset($personalInfo["ethnicity"]))
                    {
                        $ethnicity = $personalInfo["ethnicity"];
                        $fieldStr = '(ethnic_group)';
                        $valueStr = "('$ethnicity')";

                        $dbResults = $dbconn->select('Ethnicity', 'ethnicityId', "WHERE ethnic_group='" . $ethnicity . "'");

                        if (is_null($dbconn->lastException) && count($dbResults) > 0)
                        {
                            $ethnicityId = $dbResults[0]['ethnicityId'];
                        }
                        else
                        {
                            $dbconn->insert('Ethnicity', $fieldStr, $valueStr);
    
                            if (is_null($dbconn->lastException))
                            {
                                $ethnicityId = $dbconn->lastInsertId;
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }    
                        }
                    }

                    if (isset($personalInfo["disabilities"]) && count($personalInfo["disabilities"]) > 0)
                    {
                        $disabilityIds = [];

                        foreach ($personalInfo["disabilities"] as $disability)
                        {
                            $fieldStr = '(disability)';
                            $valueStr = "('$disability')";

                            $dbResults = $dbconn->select('Disability', 'disabilityId', "WHERE disability='" . $disability . "'");
    
                            if (is_null($dbconn->lastException) && count($dbResults) > 0)
                            {
                                array_push($disabilityIds, $dbResults[0]['disabilityId']);
                            }
                            else
                            {
                                $dbconn->insert('Disability', $fieldStr, $valueStr);
        
                                if (is_null($dbconn->lastException))
                                {
                                    array_push($disabilityIds, $dbconn->lastInsertId);
                                }
                                else
                                {
                                    echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                    return;
                                }    
                            }
                        }
                    }

                    $fieldStr = '';
                    $valueStr = '';

                    foreach($personalInfo as $key => $value)
                    {
                        if ($key != "addresses" && $key != "religion" && $key != "disabilities" && $key != "ethnicity" && $key != "email_addresses" && $key != "contact_numbers")
                        {
                            $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                            $fieldStr .= ($fieldStr == '' ? '' : ', ') . $key;
                        }
                    }

                    for ($i = 0; $i < count($addressIds); $i++)
                    {
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$addressIds[$i]'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . ($i == 0 ? 'present' : 'permanent') . '_addressId';
                    }

                    if (isset($religionId))
                    {
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$religionId'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'religionId';
                    }

                    if (isset($ethnicityId))
                    {
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$ethnicityId'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'ethnicityId';
                    }

                    $fieldStr = '(' . $fieldStr . ')';
                    $valueStr = '(' . $valueStr . ')';

                    $dbconn->insert('Person', $fieldStr, $valueStr);

                    if (is_null($dbconn->lastException))
                    {
                        $personId = $dbconn->lastInsertId;
                    }
                    else
                    {
                        echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                        return;
                    }

                    if (isset($personalInfo["email_addresses"]) && count($personalInfo["email_addresses"]) > 0)
                    {
                        foreach ($personalInfo["email_addresses"] as $email_address)
                        {
                            $fieldStr = '(email_address, personId)';
                            $valueStr = "('$email_address', '$personId')";

                            $dbResults = $dbconn->select('Email_Address', 'personId', "WHERE email_address='" . $email_address . "'");

                            if (is_null($dbconn->lastException))
                            {
                                if (count($dbResults) == 0)
                                {
                                    $dbconn->insert('Email_Address', $fieldStr, $valueStr);
    
                                    if (!is_null($dbconn->lastException))
                                    {
                                        echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                        return;
                                    }    
                                }
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }    
                        }
                    }

                    if (isset($personalInfo["contact_numbers"]) && count($personalInfo["contact_numbers"]) > 0)
                    {
                        foreach ($personalInfo["contact_numbers"] as $contact_number)
                        {
                            $fieldStr = '(contact_number, personId)';
                            $valueStr = "('$contact_number', '$personId')";

                            $dbconn->insert('Contact_Number', $fieldStr, $valueStr);
    
                            if (!is_null($dbconn->lastException))
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }    
                        }
                    }

                    if (isset($disabilityIds))
                    {
                        foreach ($disabilityIds as $disabilityId)
                        {
                            $fieldStr = '(disabilityId, personId)';
                            $valueStr = "('$disabilityId', '$personId')";

                            $dbconn->insert('Person_Disability', $fieldStr, $valueStr);
    
                            if (!is_null($dbconn->lastException))
                            {
                                echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                                return;
                            }
                        }
                    }

                    $fieldStr = '';
                    $valueStr = '';
                    
                    foreach ($jobApplication as $key => $value) {
                        if ($key != "personalInfo" && $key != "relevantEligibility" && $key != "relevantTraining" && $key != "relevantWorkExp")
                        {
                            $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                            $fieldStr .= ($fieldStr == '' ? '' : ', ') . $key;
                        }

                    }
                    
                    $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$personId'";
                    $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'personId';

                    $fieldStr = '(' . $fieldStr . ')';
                    $valueStr = '(' . $valueStr . ')';

                    $dbconn->insert('Job_Application', $fieldStr, $valueStr);
    
                    if (is_null($dbconn->lastException))
                    {
                        $applicationCode = $dbconn->lastInsertId;
                    }
                    else
                    {
                        echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                        return;
                    }

                    $fieldStr = '';
                    $valueStr = '';
                    
                    foreach ($jobApplication["relevantTraining"] as $relevantTraining) {
                        foreach ($relevantTraining as $key => $value) {
                            $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                            $fieldStr .= ($fieldStr == '' ? '' : ', ') . $key;
                        }
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$applicationCode'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'application_code';

                        $fieldStr = '(' . $fieldStr . ')';
                        $valueStr = '(' . $valueStr . ')';
    
                        $dbconn->insert('Relevant_Training', $fieldStr, $valueStr);
        
                        if (!is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                            return;
                        }
                    }

                    $fieldStr = '';
                    $valueStr = '';
                    
                    foreach ($jobApplication["relevantWorkExp"] as $relevantWorkExp) {
                        foreach ($relevantWorkExp as $key => $value) {
                            $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                            $fieldStr .= ($fieldStr == '' ? '' : ', ') . $key;
                        }
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$applicationCode'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'application_code';

                        $fieldStr = '(' . $fieldStr . ')';
                        $valueStr = '(' . $valueStr . ')';

                        $dbconn->insert('Relevant_Work_Experience', $fieldStr, $valueStr);
        
                        if (!is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                            return;
                        }
                    }

                    
                    foreach ($jobApplication["relevantEligibility"] as $value) {
                        $fieldStr = '';
                        $valueStr = '';

                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$value'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'eligibilityId';
    
                        $valueStr .= ($fieldStr == '' ? '' : ', ') . "'$applicationCode'";
                        $fieldStr .= ($fieldStr == '' ? '' : ', ') . 'application_code';
    
                        $fieldStr = '(' . $fieldStr . ')';
                        $valueStr = '(' . $valueStr . ')';
    
                        $dbconn->insert('Relevant_Eligibility', $fieldStr, $valueStr);

                        if (!is_null($dbconn->lastException))
                        {
                            echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage() . '\nLast SQL Statement: ' . $dbconn->lastSQLStr)));
                            return;
                        }
                    }
    
                    $param = [];
                    if (isset($_SESSION['user']['username']))
                    {
                        $param[($_SESSION['user']['is_temporary_user'] ? 'temp_' : '') . 'username'] = $_SESSION['user']['username'];
                    }
                    
                    if (isset($applicationCode))
                    {
                        $param['application_code'] = $applicationCode;
                    }

                    if (isset($jobApplication['position_title_applied']))
                    {
                        $param['position_title'] = $jobApplication['position_title_applied'];
                    }

                    if (isset($jobApplication['plantilla_item_number_applied']))
                    {
                        $param['plantilla_item_number'] = $jobApplication['plantilla_item_number_applied'];
                    }

                    logAction("mpasis", 6, $param);
                    
                    echo(json_encode(new ajaxResponse('Success', 'Application has been successfully saved with <b>Application Code: ' . $applicationCode . '</b>.')));
                    return;
                }
                break;
            case 'addTempUser':
                $person = json_decode($_REQUEST['person'], true);
                $tempUser = json_decode($_REQUEST['tempUser'], true);

                if (isset($person['given_name']))
                {
                    $fieldStr = '';
                    $valueStr = '';

                    foreach ($person as $key => $value) {
                        $valueStr .= (trim($fieldStr) == '' ? '': ', ') . "'$value'";
                        $fieldStr .= (trim($fieldStr) == '' ? '': ', ') . $key;
                    }

                    $personId = $dbconn->insert('Person', "($fieldStr)", "($valueStr)");
                    // $personId = 1;

                    if (is_null($dbconn->lastException))
                    {
                        if (isset($tempUser['username']))
                        {
                            $fieldStr = '';
                            $valueStr = '';
                            
                            $tempUser['personId'] = $personId;

                            if (isset($tempUser['password']))
                            {
                                $tempUser['password'] = trim(hash('ripemd320', $tempUser['password']));
                            }

                            foreach ($tempUser as $key => $value) {
                                $valueStr .= (trim($fieldStr) == '' ? '': ', ') . "'$value'";
                                $fieldStr .= (trim($fieldStr) == '' ? '': ', ') . $key;
                            }

                            $dbconn->insert('Temp_User', "($fieldStr)", "($valueStr)");

                            if (is_null($dbconn->lastException))
                            {
                                logAction('mpasis', 16, array(
                                    ($_SESSION['user']["is_temporary_user"] ? 'temp_' : '') . "username"=>$_SESSION['user']['username'],
                                    "temp_username_op"=>$tempUser['username']
                                ));
                                echo(json_encode(new ajaxResponse('Success', 'Temporary User successfully created')));
                                return;
                            }
                            else
                            {
                                echo(json_encode(new ajaxResponse('Error', 'Exception encountered in inserting temporary user details')));
                                return;
                            }
                        }
                        else
                        {
                            echo(json_encode(new ajaxResponse('Error', 'Username is required')));
                            return;
                        }
                    }
                    else
                    {
                        echo(json_encode(new ajaxResponse('Error', 'Exception encountered in inserting personal details')));
                        return;
                    }
                }
                else
                {
                    echo(json_encode(new ajaxResponse('Error', 'Given Name is required')));
                    return;
                }

                break;
            case 'getSalaryFromSG':
                $salaryGrade = $_REQUEST['sg'];

                $dbResults = $dbconn->select('Salary_Table', 'salary', 'WHERE salary_grade="' . $salaryGrade . '" AND step_increment=1 AND effectivity_date="2023/1/1"');
                
                if (is_null($dbconn->lastException))
                {
                    echo(json_encode(new ajaxResponse('Salary', $dbResults[0]['salary'])));
                    return;
                }
                else
                {
                    echo(json_encode(new ajaxResponse('Error', $dbconn->lastException->getMessage())));
                    return;
                }

                break;
        }
    }

    echo(json_encode(new ajaxResponse('Error', 'Unknown query.<br>')));
    var_dump($_REQUEST);
}
?>