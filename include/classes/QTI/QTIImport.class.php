<?php
/************************************************************************/
/* AContent                                                             */
/************************************************************************/
/* Copyright (c) 2010                                                   */
/* Inclusive Design Institute                                           */
/*                                                                      */
/* This program is free software. You can redistribute it and/or        */
/* modify it under the terms of the GNU General Public License          */
/* as published by the Free Software Foundation.                        */
/************************************************************************/

require_once(TR_INCLUDE_PATH.'classes/testQuestions.class.php');
require_once(TR_INCLUDE_PATH.'classes/QTI/QTIParser.class.php');	
require_once(TR_INCLUDE_PATH.'classes/DAO/TestsDAO.class.php');

/**
* QTIImport
* Class for prehandling the POST values before importing each QTI question into ATutor
* Some definitions for the QTI question type: ///
*	1	Multiple choices
*	2	True/false
*	3	Open ended
*	4	Likert
*	5	Simple Matching
*	6	Ordering
*	7	Multiple Answers
*	8	Graphical Matching
* @access	public
* @author	Harris Wong
*/
class QTIImport {
	var $qti_params	 = array();
	var $qid		 = array();		//store the question_id that is generated by this import
	var $import_path = '';
	var $title		 = '';
	var $weights	 = array();

	//Constructor
	function QTIImport($import_path){
		$this->import_path = $import_path;
	}

	//Creates the parameters array for TestQuestion::importQTI
	function constructParams($qti_params){
		global $addslashes;
		//save guarding
		$qti_params['required']		= intval($qti_params['required']);
		$qti_params['question']		= trim($qti_params['question']);
		$qti_params['category_id']	= intval($qti_params['category_id']);
		$qti_params['feedback']		= trim($qti_params['feedback']);

		//assign answers
		if (sizeof($qti_params['answers']) > 1){
			$qti_params['answer'] = $qti_params['answers'];
		} elseif (sizeof($qti_params['answers'])==1) {
			$qti_params['answer'] = intval($qti_params['answers'][0]);
		}
		$this->qti_params = $qti_params;
	}
	
	//Decide which question type to import based in the integer
	function getQuestionType($question_type){
		$qti_obj = TestQuestions::getQuestion($question_type);
		if ($qti_obj != null){
			$qid = $qti_obj->importQTI($this->qti_params);
			if ($qid  > 0) {
				$this->qid = $qid;
			}
		}
	}


	/**
	 * This function will add the attributes that are extracted from the qti xml
	 * into the database.
	 *
	 * @param	array	attributes that are extracted from the QTI XML.
	 * @return	int		the question ids.
	 */
	function importQuestions($attributes){
		global $supported_media_type, $msg;
		$qids = array();

		foreach($attributes as $resource=>$attrs){
			if (preg_match('/imsqti\_(.*)/', $attrs['type'])){
				//Instantiate class obj
				$xml = new QTIParser($attrs['type']);
				$xml_content = @file_get_contents($this->import_path . $attrs['href']);
				$xml->setRelativePath($package_base_name);

				if (!$xml->parse($xml_content)){	
					$msg->addError('QTI_WRONG_PACKAGE');
					break;
				}

				//set test title
				$this->title = $xml->title;

//if ($attrs[href] =='56B1BEDC-A820-7AA8-A21D-F32017189445/56B1BEDC-A820-7AA8-A21D-F32017189445.xml'){
//	debug($xml, 'attributes');
//}
				//import file, should we use file href? or jsut this href?
				//Aug 25, use both, so then it can check for respondus media as well.
				foreach($attrs['file'] as $file_id => $file_name){
					$file_pathinfo = pathinfo($file_name);
					if ($file_pathinfo['basename'] == $attrs['href']){
						//This file will be parsed later
						continue;
					} 

					if (in_array(strtolower($file_pathinfo['extension']), $supported_media_type)){
						//copy medias over.
						$this->copyMedia(array($file_name), $xml->items);
					}
				}		

				for ($loopcounter=0; $loopcounter<$xml->item_num; $loopcounter++){
					//Create POST values.
					unset($test_obj);		//clear cache
					$test_obj['required']		= 1;
					$test_obj['preset_num']	= 0;
					$test_obj['category_id']	= 0;
					$test_obj['question']		= $xml->question[$loopcounter];
					$test_obj['feedback']		= $xml->feedback[$loopcounter];
					$test_obj['groups']		= $xml->groups[$loopcounter];
					$test_obj['property']		= intval($xml->attributes[$loopcounter]['render_fib']['property']);
					$test_obj['choice']		= array();
					$test_obj['answers']		= array();

					//assign choices
					$i = 0;

					//trim values
					if (is_array($xml->answers[$loopcounter])){
						array_walk($xml->answers[$loopcounter], 'trim_value');
					}
					//TODO: The groups is 1-0+ choices.  So we should loop thru groups, not choices.
					if (is_array($xml->choices[$loopcounter])){		
						foreach ($xml->choices[$loopcounter] as $choiceNum=>$choiceOpt){
							if (sizeof($test_obj['groups'] )>0 && is_array($xml->answers[$loopcounter])) {
								if (!empty($xml->answers[$loopcounter])){
									foreach ($xml->answers[$loopcounter] as $ansNum=>$ansOpt){
										if ($choiceNum == $ansOpt){
											//Not exactly efficient, worst case N^2
											$test_obj['answers'][$ansNum] = $i;
										}			
									}
								}
							} else {
								//save answer(s)
								if (is_array($xml->answers[$loopcounter]) && in_array($choiceNum, $xml->answers[$loopcounter])){
									$test_obj['answers'][] = $i;
								}		
							}
							$test_obj['choice'][] = $choiceOpt;
							$i++;
						}
					}

		//			unset($qti_import);
					$this->constructParams($test_obj);
//debug($xml->getQuestionType($loopcounter), 'lp_'.$loopcounter);
					//Create questions
					$this->getQuestionType($xml->getQuestionType($loopcounter));

					//save question id 
					$qids[] = $this->qid;

					//Dependency handling
					if (!empty($attrs['dependency'])){
						$xml_items = array_merge($xml_items, $xml->items);
					}
				}

				//assign title
				if ($xml->title != ''){
					$this->title = $xml->title;
				}

				//assign marks/weights
				$this->weights = $xml->weights;

				$xml->close();
			} elseif ($attrs['type'] == 'webcontent') {
				//webcontent, copy it over.
				$this->copyMedia($attrs['file'], $xml_items);
			}
		}
//debug($qids, 'qids');
		return $qids;
	}

	/**
	 * This function is to import a test and returns the test id.
	 * @param	string	custmom test title
	 *
	 * @return	int		test id
	 */
	function importTest($title='') {
		global $_course_id;
		
		$title = ($title=='')?$this->title:$title;
		
		$testsDAO = new TestsDAO();
		$tid = $testsDAO->Create($_course_id, 
								$title, 
								$test_obj['description']);

//			$sql_params = array (	$_SESSION['course_id'], 
//									$test_obj['title'], 
//									$test_obj['description'], 
//									$test_obj['format'], 
//									$start_date, 
//									$end_date, 
//									$test_obj['order'], 
//									$test_obj['num_questions'], 
//									$test_obj['instructions'], 
//									$test_obj['content_id'], 
//									$test_obj['passscore'], 
//									$test_obj['passpercent'], 
//									$test_obj['passfeedback'], 
//									$test_obj['failfeedback'], 
//									$test_obj['result_release'], 
//									$test_obj['random'], 
//									$test_obj['difficulty'], 
//									$test_obj['num_takes'], 
//									$test_obj['anonymous'], 
//									'', 
//									$test_obj['allow_guests'], 
//									$test_obj['display']);
//
//			$sql = vsprintf(AT_SQL_TEST, $sql_params);
//			$result = mysql_query($sql, $db);
//			$tid = mysql_insert_id($db);
		//debug($qti_import->weights, 'weights');			
		return $tid;
	}


	/*
	 * Match the XML files to the actual files found in the content, then copy the media 
	 * over to the content folder based on the actual links.  *The XML file names might not be right.
	 * @param	array	The list of file names provided by the manifest's resources
	 * @param	array	The list of relative files that is used in the question contents.  Default empty.
	 */
	function copyMedia($files, $xml_items = array()){
		global $msg, $_course_id;
		foreach($files as $file_num => $file_loc){
			//skip test xml files
			if (preg_match('/tests\_[0-9]+\.xml/', $file_loc)){
				continue;
			}

			$new_file_loc ='';

			/**
				Use the items list to handle and check which path it is from, so then it won't blindly truncate 'resource/' from the path
				- For any x in xml_files, any y in new_file_loc, any k in the set of strings; such that k concat x = y, then use y, else use x
				- BUG: Same filename fails.  If resource/folder1/file1.jpg, and resource/file1.jpg, both will get replaced with file1.jpg
			*/
			if(!empty($xml_items)){
				foreach ($xml_items as $xk=>$xv){
					if (($pos = strpos($file_loc, $xv))!==false){
						//address the bug mentioned aboe.
						//check if there is just one level of directory in this extra path.
						//based on the assumption that most installation are just using 'resources/' or '{FOLDER_NAME}/'
						$shortened = substr($file_loc, 0, $pos);
						$num_of_occurrences = explode('/', $shortened);
						if (sizeof($num_of_occurrences) == 2){
							$new_file_loc = $xv;
							break;
						}
					} 
				}
			}

			if ($new_file_loc==''){
				$new_file_loc = $file_loc;
			}
		
			//Check if the file_loc has been changed, if not, don't move it, let ims class to handle it
			//we only want to touch the files that the test/surveys use
			if ($new_file_loc!=$file_loc){
				//check if new folder is there, if not, create it.
				createDir(TR_CONTENT_DIR .$_course_id.'/'.$new_file_loc );
				
				//overwrite files
				if (file_exists(TR_CONTENT_DIR .$_course_id.'/'.$new_file_loc)){
					unlink(TR_CONTENT_DIR .$_course_id.'/'.$new_file_loc);
				}
				if (file_exists($this->import_path.$file_loc)){
					if (copy($this->import_path.$file_loc, 
						TR_CONTENT_DIR .$_course_id.'/'.$new_file_loc) === false) {
						//TODO: Print out file already exist error.
						if (!$msg->containsErrors()) {
			//				$msg->addError('FILE_EXISTED');
						}
					}
				}
			}
		}
	}
}
?>
