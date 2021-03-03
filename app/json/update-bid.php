<?php

    require_once '../include/common.php';
    require_once '../include/token.php';
    require_once '../process_add_bid.php';
    require_once 'json_process_min_bid.php';
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

                if(!array_key_exists("amount", $tempArr)){
                    array_push($errors, "missing amount");
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
                $prerequisitedao = new PrerequisiteDAO();
                $coursecompleteddao = new CourseCompletedDAO();
                $biddingrounddao = new BiddingRoundDAO();
                $successfuldao = new SuccessfulDAO();
                $sectionresultsdao = new SectionResultsDAO();
    
                $userid = $tempArr["userid"];
                $amount = $tempArr["amount"];
                $course = $tempArr["course"];
                $section = $tempArr["section"];

                $status = $biddingrounddao->get_status(); // Not Started, Ongoing, Ended
                $round = $biddingrounddao->get_round(); // 1, 2

                //invalid amount
                if(is_numeric($amount)){
                    if((int)$amount < 10 || strlen(substr(strrchr($amount, "."), 1)) > 2){
                        array_push($errors, "invalid amount");
                    }
                }
                else{
                    array_push($errors, "invalid amount");
                }

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

                //invalid userid check
                if(!$studentdao->validUser($userid)){
                    array_push($errors, "invalid userid");
                }

                if(isEmpty($errors)){
                    //round ended
                    if($status != "Ongoing"){
                        array_push($errors, "round ended");
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

                    if($biddao->course_bidded_exists($userid, $course, $round)){
                        $previous_bid_amount = $biddao->get_course_amount($userid, $course, $round);
                        $current_balance = $studentdao->get_balance($userid);
                        $balance_after_dropping = $previous_bid_amount + $current_balance;

                        if($amount > $balance_after_dropping){
                            array_push($errors, "insufficient e$");
                        }
                        else{ // sufficient e-$
                            if($round == 2){ // if round 2, update real-time bid info
                                $min_bid = process_min_bid($course, $section);
                                if($amount < $min_bid){
                                    array_push($errors, "bid too low");
                                }
                            }
                        }
                    }
                    else{
                        if($amount > $studentdao->get_balance($userid)){
                            array_push($errors, "insufficient e$");
                        }
                        else{
                            if($round == 2){ // if round 2, update real-time bid info
                                $min_bid = process_min_bid($course, $section);
                                if($amount < $min_bid){
                                    array_push($errors, "bid too low");
                                }
                            }
                        }
                    }

                    $pending_bidded_sections = $biddao->get_pending_bids_and_amount($userid, $round);

                    //class timetable clash
                    //exam timetable clash
                    if(!$biddao->course_bidded_exists($userid, $course, $round)){
                        $no_clash_check_success = true;
                        if($sectiondao->is_valid_section($course, $section)){
                            $bidding_class = $sectiondao->get_class_day_start_end($course, $section);
                            foreach($pending_bidded_sections as $this_list) {
                                $existing_courseid = $this_list[0];
                                $existing_section = $this_list[1];
                
                                $existing_class = $sectiondao->get_class_day_start_end($existing_courseid, $existing_section);
                                $class_clash_check = dont_clash($bidding_class[0], $bidding_class[1], $bidding_class[2], $existing_class[0], $existing_class[1], $existing_class[2]);
                
                                $bidding_exam = $coursedao->get_exam_date_start_end($course);
                                $existing_exam = $coursedao->get_exam_date_start_end($existing_courseid);
                                $exam_clash_check = dont_clash($bidding_exam[0], $bidding_exam[1], $bidding_exam[2], $existing_exam[0], $existing_exam[1], $existing_exam[2]);
                
                                $no_clash_check_success = $class_clash_check && $exam_clash_check;
                
                                if(!$no_clash_check_success) {
                                    $no_clash_check_success = false;
                                    if(!$class_clash_check) {
                                        array_push($errors, "class timetable clash");
                                    }
                                    if(!$exam_clash_check) {
                                        array_push($errors, "exam timetable clash");
                                    }
                                }
                            }

                            if($round == 2) {
                                // check clash with ROUND 1 SUCCESSFUL bids
                                $bidding_class = $sectiondao->get_class_day_start_end($course, $section);
                                $round1_successful_bids = $successfuldao->get_successful_bids_and_amount($userid, 1);

                                foreach($round1_successful_bids as [$existing_courseid, $existing_section, $this_amount]) {

                                    $existing_class = $sectiondao->get_class_day_start_end($existing_courseid, $existing_section);
                                    $class_clash_check = dont_clash($bidding_class[0], $bidding_class[1], $bidding_class[2], $existing_class[0], $existing_class[1], $existing_class[2]);

                                    $bidding_exam = $coursedao->get_exam_date_start_end($course);
                                    $existing_exam = $coursedao->get_exam_date_start_end($existing_courseid);
                                    $exam_clash_check = dont_clash($bidding_exam[0], $bidding_exam[1], $bidding_exam[2], $existing_exam[0], $existing_exam[1], $existing_exam[2]);

                                    $no_clash_check_success = $class_clash_check && $exam_clash_check;

                                    if(!$no_clash_check_success) {
                                        $no_clash_check_success = false;
                                        if(!$class_clash_check) {
                                            array_push($errors, "class timetable clash");
                                        }
                                        if(!$exam_clash_check) {
                                            array_push($errors, "exam timetable clash");
                                        }
                                    }           
                                } 


                            }
                        }
                    }     
    
                    //incomplete prerequisites
                    $prerequisites_needed = $prerequisitedao->get_prerequisite_courses($course);
                    $student_completed_courses = $coursecompleteddao->get_completed_courses($userid);
                    foreach($prerequisites_needed as $this_prerequisite) {
                        if(!in_array($this_prerequisite, $student_completed_courses)) {
                            array_push($errors, "incomplete prerequisites");
                        }
                    }

                    //course completed
                    if(in_array($course, $student_completed_courses)){
                        array_push($errors, "course completed");
                    }
    
                    //course enrolled
                    if($round == 2){
                        $enrolled = $successfuldao->check_success($userid, $course, $section, 1);
                        $successful_bids = $successfuldao->get_student_successful_bids($userid, 1);
                        if($enrolled){
                            array_push($errors, "course enrolled");
                        }
                        else{
                            foreach($successful_bids as $bids){
                                if(in_array($course, $bids)){
                                    array_push($errors, "course enrolled");
                                }
                            }
                        }
                    }


                    //section limit reached
                    if($round == 1) { // round 1 ongoing
                        $pending_bidded_sections = $biddao->get_pending_bids_and_amount($userid, 1);

                        if(!$biddao->course_bidded_exists($userid, $course, 1)) {
                            // if this is a NEW bid
                            // (if update of prev bid, definitely won't exceed section limit)
                            if(count($pending_bidded_sections) == 5) {
                                array_push($errors, "section limit reached");
                            }
                        }
                    } else { // round 2 ongoing
                        $round1_successful_bids = $successfuldao->get_successful_bids_and_amount($userid, 1);

                        $pending_bidded_sections = $biddao->get_pending_bids_and_amount($userid, 2);

                        $total_num_bids = count($pending_bidded_sections) + count($round1_successful_bids);

                        if($total_num_bids == 5) {
                            array_push($errors, "section limit reached");
                        }
                    }
    
                    //not own school course
                    if($round == 1){
                        if($coursedao->get_school($course) != $studentdao->get_school($userid)) {
                            array_push($errors, "not own school course");
                        }
                    }
    
                    //no vacancy
                    if($round == 1){
                        $size = $sectiondao->get_size($course, $section);
                        if($size == 0){
                            array_push($errors, "no vacancy");
                        }
                    }
                    else{
                        if($round == 2){
                            $seats = $sectionresultsdao->get_available_seats($course, $section);
                            if($seats == 0){
                                array_push($errors, "no vacancy");
                            }
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
                        if($biddao->course_bidded_exists($userid, $course, $round)){
                            $previous_bid_amount = $biddao->get_course_amount($userid, $course, $round); // bid to be replaced
                            $success = $biddao->update_bid($userid, $amount, $course, $section, $round) && $studentdao->add_balance($userid, $previous_bid_amount) && $studentdao->deduct_balance($userid, $amount);
                            if($success){
                                $result = [
                                    "status" => "success"
                                ];

                                if($round == 2){
                                    process_min_bid($course, $section);
                                }
                            }
                        } else { // NEW bid
                            $success = $biddao->add_bid($userid, $amount, $course, $section, $round) && $studentdao->deduct_balance($userid, $amount);
                            if($success){
                                $result = [
                                    "status" => "success"
                                ];

                                if($round == 2){
                                    process_min_bid($course, $section);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);

?>