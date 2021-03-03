<?php

function json_close_bidding_round2(){
    $biddao = new BidDAO();
    $sectiondao = new SectionDAO();
    $sectionresultsdao = new SectionResultsDAO();
    $biddingrounddao = new BiddingRoundDAO();
    $successfuldao = new SuccessfulDAO();
    $studentdao = new StudentDAO();
    $unsuccessfuldao = new UnsuccessfulDAO();

    $round2_bids_with_status = $biddao->retrieve_sort_bids(2);

    foreach($round2_bids_with_status as $course_section_str => $bid_list) {
        [$this_course, $this_section] = explode(", ", $course_section_str);

        foreach($bid_list as [$this_userid, $this_amount, $this_status]) {
            if($this_status == "Pending, successful") {
                $successfuldao->add_success($this_userid, $this_amount, $this_course, $this_section, 2);

            } elseif($this_status == "Pending, fail") {
                $studentdao->add_balance($this_userid,$this_amount); // bid fail, so refund
                $unsuccessfuldao->add_unsuccessful($this_userid, $this_amount, $this_course, $this_section, 2);

            }
        }
    }
}

?>