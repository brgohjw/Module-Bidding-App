<?php

    require_once '../include/common.php';
    require_once '../include/token.php';
    $sortclass = new Sort();

    // isMissingOrEmpty(...) is in common.php
    $errors = [ isMissingOrEmpty ("token"), isMissingOrEmpty ("r")];
    $errors = array_filter($errors);
    
    if(isset($_GET["token"]) && $_GET["token"] != "") {
        if(!verify_token($_GET["token"])) { // if invalid token
            $errors[] = "invalid token";
        }
    }

    // if there are common validation errors
    if(!isEmpty($errors)) {
        $errors = $sortclass->sort_it($errors, "common_validation");

        $result = [
            "status" => "error",
            "message" => array_values($errors)
        ];
    } else { // if there are no common validation errors
        if(isset($_GET["r"])){

            $tempArr = json_decode($_GET["r"], true);
    
            $errors = [];
    
            if(!is_array($tempArr)){
                array_push($errors, "invalid format");
            }
            else{
                foreach($tempArr as $key => $value){
                    if(str_replace(' ', '' , $value) == ''){
                        array_push($errors, "blank $key");
                    }
                }
                
                if(!array_key_exists("userid", $tempArr)){
                    array_push($errors, "missing userid");
                }

                if(!array_key_exists("course", $tempArr)){
                    array_push($errors, "missing course");
                }

                if(!array_key_exists("section", $tempArr)){
                    array_push($errors, "missing section");
                }
            }
    
            if(!isEmpty($errors)){
                $errors = $sortclass->sort_it($errors, "common_validation");
                $result = [
                    "status" => "error",
                    "message" => $errors
                ];
            }
            else{
                $studentdao = new StudentDAO();
                $coursedao = new CourseDAO();
                $sectiondao = new SectionDAO();
                $biddao = new BidDAO();
                $biddingrounddao = new BiddingRoundDAO();
                $successfuldao = new SuccessfulDAO();

                $userid = $tempArr["userid"];
                $course = $tempArr["course"];
                $section = $tempArr["section"];

                $status = $biddingrounddao->get_status();
                $round = $biddingrounddao->get_round();

                //invalid course check
                if(!$coursedao->get_course($course)){
                    array_push($errors, "invalid course");
                }

                //invalid userid check
                if(!$studentdao->validUser($userid)){
                    array_push($errors, "invalid userid");
                }

                //invalid section check
                if($coursedao->get_course($course)){
                    if(!$sectiondao->is_valid_section($course, $section)){
                        array_push($errors, "invalid section");
                    }
                }

                //round not active
                if($status != "Ongoing"){
                    array_push($errors, "round not active");
                }

                if(!isEmpty($errors)){
                    sort($errors);
                    $result = [
                        "status" => "error",
                        "message" => $errors
                    ];
                }
                else{
                    if($successfuldao->check_success($userid, $course, $section, 1)){
                        $amount = $successfuldao->get_specific_bid($userid, $course, $section, 1);
                        $success = $successfuldao->drop_section($userid, $course, $section);
                        if($success){
                            $studentdao->add_balance($userid, $amount[1]);
                            $result = [
                                "status" => "success"
                            ];
                        }
                    }
                    else{
                        $result = [
                            "status" => "error",
                            "message" => ["no such enrollment found"]
                        ];
                    }
                }
            }
        }
        else{
            $result =[
                "status" => "error",
                "message" => ["HTTP REQUEST NOT FOUND"]
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);

?>