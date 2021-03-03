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
                $coursedao = new CourseDAO();
                $sectiondao = new SectionDAO();
                $biddao = new BidDAO();
                $biddingrounddao = new BiddingRoundDAO();
                $successfuldao = new SuccessfulDAO();

                $course = $tempArr["course"];
                $section = $tempArr["section"];

                $round = $biddingrounddao->get_current_round();

                //invalid course check
                if(!$coursedao->get_course($course)){
                    array_push($errors, "invalid course");
                }

                //invalid section check
                if($coursedao->get_course($course)){
                    if(!$sectiondao->is_valid_section($course, $section)){
                        array_push($errors, "invalid section");
                    }
                }

                if(!isEmpty($errors)){
                    sort($errors);
                    $result = [
                        "status" => "error",
                        "message" => $errors
                    ];
                }
                else{
                    $success = [];

                    if($round == 1.5 || $round == 2 ){
                        $bids = $successfuldao->retrieve_successful_bids($course, $section, 1);
                        $sortclass = new Sort();
                        
                        $bids = $sortclass->sort_it($bids, "section_dump");
                        for($i=0; $i<count($bids); $i++){
                            $userid = $bids[$i][0];
                            $amount = $bids[$i][1];

                            $res = [
                                "userid" => $userid,
                                "amount" => floatval($amount),
                            ];

                            array_push($success, $res);
                        }

                        $result = [
                            "status" => "success",
                            "students" => $success
                        ];
                    }
                    else if($round == 2.5){
                        $round1_bids = $successfuldao->retrieve_successful_bids($course, $section, 1);
                        $round2_bids = $successfuldao->retrieve_successful_bids($course, $section, 2);
                        $bids = array_merge($round1_bids, $round2_bids);
                        $sortclass = new Sort();
                        
                        $bids = $sortclass->sort_it($bids, "section_dump");
                        for($i=0; $i<count($bids); $i++){
                            $userid = $bids[$i][0];
                            $amount = $bids[$i][1];

                            $res = [
                                "userid" => $userid,
                                "amount" => floatval($amount),
                            ];

                            array_push($success, $res);
                        }

                        $result =[
                            "status" => "success",
                            "students" => $success
                        ];
                    }
                    else{
                        $result = [
                            "status" => "success",
                            "students" => $success
                        ];
                    }
                }
            }
        }
        else{
            $result = [
                "status" => "error",
                "message" => ["HTTP REQUEST NOT FOUND"]
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);

?>