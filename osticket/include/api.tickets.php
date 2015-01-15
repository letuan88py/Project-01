<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.thread.php';//new
include_once INCLUDE_DIR.'class.file.php';// new
include_once INCLUDE_DIR.'class.staff.php';// new
//include_once INCLUDE_DIR.'class.lock.php';// new
class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId"
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($form = $topic->getForm())) {
            foreach ($form->getDynamicFields() as $field)
                $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type',
                'flags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, 'Unexpected or invalid data received');

        //Nuke attachments IF API files are not allowed.
        if(!$ost->getConfig()->allowAPIAttachments())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$attachment) {
                if(!$ost->isFileTypeAllowed($attachment))
                    $attachment['error'] = 'Invalid file type (ext) for '.Format::htmlchars($attachment['name']);
                elseif ($attachment['encoding'] && !strcasecmp($attachment['encoding'], 'base64')) {
                    if(!($attachment['data'] = base64_decode($attachment['data'], true)))
                        $attachment['error'] = sprintf('%s: Poorly encoded base64 data', Format::htmlchars($attachment['name']));
                }
                if (!$attachment['error']
                        && ($size = $ost->getConfig()->getMaxFileSize())
                        && ($fsize = $attachment['size'] ?: strlen($attachment['data']))
                        && $fsize > $size) {
                    $attachment['error'] = sprintf('File %s (%s) is too big. Maximum of %s allowed',
                            Format::htmlchars($attachment['name']),
                            Format::file_size($fsize),
                            Format::file_size($size));
                }
            }
            unset($attachment);
        }
        return true;
    }

    #by le tuan
    function task(){
      $apictl=new ApiController();//getTask in class.api.php
        if(!$apictl->getTask())
         return $this->exerr(401, 'API task not found');   
        
      switch ($apictl->getTask()){
          case 'create':
              TicketApiController::create($apictl->getFormatClient());
              break;
          case 'view':
              TicketApiController::view($apictl->getIdClient(),$apictl->getFormatClient());
              break;
          case 'check':
              TicketApiController::check($apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'test':
              TicketApiController::test($apictl->getIdClient(),$apictl->getEmailClient());
              break;
          case 'checkAdmin':
              TicketApiController::checkAdmin($apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'postReply':
              TicketApiController::postReplyCustomer($apictl->getIdClient(),$apictl->getFormatClient());
              break;
          case 'postReplyAdmin':
              TicketApiController::postReplyAdmin($apictl->getIdClient(),$apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'status':
              TicketApiController::status($apictl->getFormatClient(),$apictl->getStatus());
              break;
          case 'download':
              TicketApiController::sendfile($apictl->getIdClient(),$apictl->getAttachId());
              break;
          case 'deleteTicket':
              TicketApiController::deleteTicket($apictl->getFormatClient(),$apictl->getEmailClient());
              break;
          case 'delete':
              TicketApiController::deleteticketApi($apictl->getFormatClient());
              break;
          case 'assign':
              TicketApiController::assignApi($apictl->getIdClient(),$apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'update':
              TicketApiController::updateTicket($apictl->getIdClient(),$apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'getOption':
              TicketApiController::getOption();
              break;
          case 'deptTransfer':
              TicketApiController::deptTransfer($apictl->getIdClient(),$apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'deleteThread':
              TicketApiController::deleteThread($apictl->getIdClient(),$apictl->getEmailClient(),$apictl->getFormatClient());
              break;
          case 'getStaffInfor':
              TicketApiController::getStaffInfor($apictl->getEmailClient());
              break;
        }
    }
    #by Letuan
    
    
    function getStaffInfor($emailStaff){      
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $this->response(201, json_encode($classStaff));
    }
    # by letuan
    function deleteThread($ticket_id, $emailStaff, $format){
        global $thisstaff;
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
                
        $ticket=Ticket::lookup($ticket_id);
        if(!$ticket){
             $this->response(401, 'Ticket not found');
             exit(-1);
        }  
        $req=$this->getRequest($format);
         $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        $query='DELETE FROM ost_ticket_thread WHERE ost_ticket_thread.id ='.$req['thread_id'];
        if(db_query("$query")){
        $msg='Successfully to Delete Thread #'.$req['thread_id'];
        }else $msg='Unable to Delete thread!';
        $this->response(201, json_encode($msg));
    }
    # by letuan
    function getOption(){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        $data=array();
        
        //Select Help Topic
        $queryTopic='SELECT topic_id, topic FROM ost_help_topic';        
        $res1=db_query("$queryTopic");
        if(!$res1 && !db_num_rows($res1) ){
            $data['helpTopic']='errors query';
        }else{
            while($row=db_fetch_array($res1)){
                $ar_row[]=$row;
            }
            $data['helpTopic']=$ar_row;
        }
        
        //Select SLA Plan
        $querySla='SELECT id, name FROM ost_sla';        
        $resS=db_query("$querySla");
        if(!$resS && !db_num_rows($resS) ){
            $data['slaPlan']='errors query';
        }else{
            while($rowS=db_fetch_array($resS)){
                $ar_rowS[]=$rowS;
            }
            $data['slaPlan']=$ar_rowS;
        }
        //Select Priority Level
        $queryPri='SELECT priority_id, priority_desc FROM ost_ticket_priority';        
        $resP=db_query("$queryPri");
        if(!$resP && !db_num_rows($resP) ){
            $data['priorityLevel']='errors query';
        }else{
            while($rowP=db_fetch_array($resP)){
                $ar_rowP[]=$rowP;
            }
            $data['priorityLevel']=$ar_rowP;
        }
        // select Staff
        
        $queryStaff='SELECT staff_id, group_id, dept_id, firstname, lastname, email, phone, phone_ext FROM ost_staff';        
        $resStaff=db_query("$queryStaff");
        if(!$resStaff && !db_num_rows($resStaff) ){
            $data['staff']='errors query';
        }else{
            while($rowStaff=db_fetch_array($resStaff)){
                $ar_rowStaff[]=$rowStaff;
            }
            $data['staff']=$ar_rowStaff;
        }
        
        // select Teams
        $queryTeam='SELECT team_id, name FROM ost_team';        
        $resTeam=db_query("$queryTeam");
        if(!$resTeam && !db_num_rows($resTeam) ){
            $data['Teams']='errors query';
        }else{
            while($rowTeam=db_fetch_array($resTeam)){
                $ar_rowTeam[]=$rowTeam;
            }
            $data['Teams']=$ar_rowTeam;
        }
        
        //select Group
        $queryGroup='SELECT group_id, group_name FROM ost_groups';        
        $resGroup=db_query("$queryGroup");
        if(!$resGroup && !db_num_rows($resGroup) ){
            $data['Groups']='errors query';
        }else{
            while($rowGroup=db_fetch_array($resGroup)){
                $ar_rowGroup[]=$rowGroup;
            }
            $data['Groups']=$ar_rowGroup;
        }
        
        // Select Dept
        $queryDept='SELECT dept_id, dept_name FROM ost_department';        
        $resDept=db_query("$queryDept");
        if(!$resDept && !db_num_rows($resDept) ){
            $data['Dept']='errors query';
        }else{
            while($rowDept=db_fetch_array($resDept)){
                $ar_rowDept[]=$rowDept;
            }
            $data['Dept']=$ar_rowDept;
        }
        
        $this->response(201,json_encode($data));
    }
    #by letuan
    function deptTransfer($ticket_id,$emailStaff,$format){
        global $thisstaff;
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
                
        $ticket=Ticket::lookup($ticket_id);
        if(!$ticket){
             $this->response(401, 'Ticket not found');
             exit(-1);
        }  
        $req=$this->getRequest($format);
         $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        
    //  Check permission
            if(!$thisstaff->canTransferTickets())
                $errors['err']=$errors['transfer'] = 'Action Denied. You are not allowed to transfer tickets.';
            else {
                //Check target dept.
                if(!$req['deptId'])
                    $errors['deptId'] = 'Select department';
                elseif($req['deptId']==$ticket->getDeptId())
                    $errors['deptId'] = 'Ticket already in the department';
                elseif(!($dept=Dept::lookup($req['deptId'])))
                    $errors['deptId'] = 'Unknown or invalid department';

                //Transfer message - required.
                if(!$req['transfer_comments'])
                    $errors['transfer_comments'] = 'Transfer comments required';
                elseif(strlen($req['transfer_comments'])<5)
                    $errors['transfer_comments'] = 'Transfer comments too short!';

                //If no errors - them attempt the transfer.
                if($ticket->transfer($req['deptId'], $req['transfer_comments'])) {
                   $msg = 'Ticket transferred successfully to '.$ticket->getDeptName();
                   TicketLock::removeStaffLocks($thisstaff->getId(), $ticket->getId());
                    //Check to make sure the staff still has access to the ticket
                    if(!$ticket->checkStaffAccess($thisstaff))
                        $ticket=null;

                } elseif(!$errors['transfer']) {
                    $errors['err'] = 'Unable to complete the ticket transfer';
                    $errors['transfer']='Correct the error(s) below and try again!';
                    $msg=$errors;
                }
            }
        $this->response(201, json_encode($msg));
    }
    
    #by letuan
    function updateTicket($ticket_id,$emailStaff,$format){
        global $thisstaff;
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
                
        $ticket=Ticket::lookup($ticket_id);
        if(!$ticket){
             $this->response(401, 'Ticket not found');
             exit(-1);
        }  
        $req=$this->getRequest($format);
        
        $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        if(!$ticket || !$thisstaff->canEditTickets()){
            $errors['err']='Permission Denied. You are not allowed to edit tickets';
        }else{
           $query="UPDATE `ost_ticket__cdata` SET `subject` = '".$req['subject']."', `priority_id` = '".$req['priority_id']."' WHERE `ost_ticket__cdata`.`ticket_id` ='".$ticket_id."'";
             if(db_query($query)&& $ticket->update($req,$errors)){
                 $msg='Ticket updated successfully';
             }else {
                 foreach($errors as $value ){
                     $msg.='#'.$value;
                 }
             }
        }
        $this->response(201,  json_encode($msg));
    }
    #by letuan
    function assignApi($ticket_id,$emailStaff,$format){
        global $thisstaff;
        
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $ticket=Ticket::lookup($ticket_id);
        if(!$ticket){
             $this->response(401, 'Ticket not found');
             exit(-1);
        }
        $req=$this->getRequest($format);
        
        $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        
        $assignId='';
        $claim=false;
       if(!$req['assignType']){
           $this->response(401, 'Assign Type not found');
             exit(-1);
       }else{
           if($req['assignType']=='s'){
               $query='SELECT staff_id FROM ost_staff WHERE staff_id=';
               $query .= $req['assignId'];
               
               if(($res=db_query("$query")) && db_num_rows($res)){
                   $assignId='s'.$req['assignId'];
                   $claim=true;
               }else {
                   $this->response(401, 'Staff not registry please registry to user');
                   exit(-1);
               }
           }
           if($req['assignType']=='t'){
               $query='SELECT team_id FROM ost_team WHERE team_id=';
               $query .= $req['assignId'];
               
               if(($res=db_query("$query")) && db_num_rows($res)){
                   $assignId='t'.$req['assignId'];
                   $claim=true;
               }else {
                   $this->response(401, 'TEAM not found');
                   exit(-1);
               }
           }
       }
        if($ticket->assign($assignId,$req['comments'],$claim)){
            $msg='Ticket assigned successfully to '.$ticket->getAssigned();
            TicketLock::removeStaffLocks($thisstaff->getId(), $ticket->getId());
            $ticket=null;
        }else {
            $msg='Unable to assigned Ticket!';
        }
        $this->response(201,json_encode($msg));
    }
    
    # by letuan
    function deleteTicket($format,$emailStaff){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        if(!$thisstaff->canDeleteTickets()) {
          $arr['error']='Permission Denied. You are not allowed to DELETE tickets!!';
        }else{
            $req=$this->getRequest($format);
            $arr=array();
            foreach ($req as $ticket_id){
             $arr[]= $this->deletetk($ticket_id);
            }
        }
        $this->response(201,json_encode($arr));
    }
    function deleteticketApi($format){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $req=$this->getRequest($format);
        $arr=array();
        foreach ($req as $ticket_id){
         $arr[]= $this->deletetk($ticket_id);
        }
        $this->response(201,json_encode($arr));
    }
    
   function deletetk($ticket_id){
         $ticket=Ticket::lookup($ticket_id);
         if(!$ticket){
             return  'Ticket not found';
         }
         $ticketnumber=$ticket->getNumber();
         if($ticket->delete()){
               return $ticketnumber;
         }
    }
    #by le tuan
    
    function checkAdmin($emailStaff,$format){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $classStaff= new Staff;
        if(!$classStaff->Staff($emailStaff)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        $staffId=$thisstaff->ht;
        $statusCount=$thisstaff->getTicketsStats();
        $allTicket=$statusCount['open']+$statusCount['closed']+$statusCount['answered'];
//        $statusCount['allTicket']=$allTicket;
        $req=$this->getRequest($format);
        
        $query='SELECT ost_ticket.ticket_id,ost_ticket.staff_id,ost_ticket.number,ost_ticket.created,ost_ticket.status'
                . ',ost_ticket__cdata.subject,ost_help_topic.topic,ost_department.dept_name,'
                . 'ost_ticket_priority.priority,ost_team.name AS teamname,ost_user_email.address,ost_ticket.isanswered,ost_staff.email'
                . ', ost_staff.firstname, ost_user.name AS username'
                . ' FROM ost_ticket'
                . ' LEFT JOIN ost_ticket__cdata ON (ost_ticket.ticket_id=ost_ticket__cdata.ticket_id)'
                . ' LEFT JOIN ost_help_topic ON (ost_ticket.topic_id=ost_help_topic.topic_id)'
                . 'LEFT JOIN ost_department ON (ost_ticket.dept_id=ost_department.dept_id)'
                . 'LEFT JOIN ost_ticket_priority ON (ost_ticket_priority.priority_id=ost_ticket__cdata.priority_id)'
                . 'LEFT JOIN ost_staff ON (ost_staff.staff_id=ost_ticket.staff_id)'
                . 'LEFT JOIN ost_team ON (ost_ticket.team_id=ost_team.team_id) '
                . 'LEFT JOIN ost_user_email ON (ost_ticket.user_id=ost_user_email.user_id)'
                . 'LEFT JOIN ost_user ON (ost_ticket.user_id=ost_user.id)';
        
    switch($req['status']){ //Status is overloaded
        case 'open':
            $qwhere=' WHERE ost_ticket.isanswered=0 AND ost_ticket.staff_id=0 AND ost_ticket.status='."'".$req['status']."'"; // Open Ticket
            break;
        case 'closed':
            $qwhere=' WHERE ost_ticket.status='."'".$req['status']."'"; //  Closed Ticket
            break;
        case 'myticket':
            $qwhere=' WHERE ost_ticket.status="open" AND ost_ticket.staff_id='.$staffId['staff_id'];// My Ticket
            break;
        case 'answered':
            $qwhere=' WHERE ost_ticket.status="open" AND ost_ticket.isanswered=1'; // Answered Ticket
            break;
        default:
            $qwhere=' WHERE ost_ticket.isanswered=0 AND ost_ticket.status="open"'; 
    }  
        $query.=$qwhere;

        $qeroSea='AND ost_ticket.ticket_id=-1';
        $pattern="/\*|;|\'|\`|\\|\"|\ /";
        if(!($search=$req['search'])){
            
        }else{
            if(($searchId=$search['ticketId'])){
            
                if((empty($searchId['from']))){
                    
                } elseif(!(preg_match($pattern,$searchId['from']))&& preg_match("/^[0-9]{1,5}$/i",$searchId['from'])){                   
                    $query.=" AND ost_ticket.ticket_id >=".$searchId['from'];
                }else $query.=$qeroSea;
                
                if((empty($searchId['to']))){
                    
                } elseif(!(preg_match($pattern,$searchId['from']))&& preg_match("/^[0-9]{1,5}$/i",$searchId['to'])){                   
                     $query.=" AND ost_ticket.ticket_id <=".$searchId['to'];
                }else $query.=$qeroSea;                  
                
            }
            
            if(($searchDate=$search['date'])){
                if((empty($searchDate['from']))){
                    } else{
                        if(!(preg_match("/\*|;|\'|\`|\" /",$searchDate['from'])) && !(preg_match("/^[A-Z]$/i",$searchDate['from']))) {
                        $query.=" AND ost_ticket.created >='".$searchDate['from']."'";
                    }else $query.=$qeroSea;                      
                
                }
                if((empty($searchDate['to']))){
                } else
                    if(!(preg_match("/\*|;|\'|\`|\" /",$searchDate['to'])) && !(preg_match("/^[A-Z]$/i",$searchDate['to']))) {              
                    $query.=" AND ost_ticket.created <='".$searchDate['to']."'";
                }else $query.=$qeroSea; 
            }
            
            if(($searchNumber=$search['number'])){
                if(!(preg_match($pattern,$searchNumber)) && preg_match("/^[0-9]{1,6}$/i",$searchNumber)){
                $query.=" AND ost_ticket.number LIKE '%".$searchNumber."%'";
                }else $query.=$qeroSea;
            }  
            if(($searchSubject=$search['subject'])){
                if(!(preg_match($pattern,$searchSubject))){
                $query.=" AND ost_ticket__cdata.subject LIKE '%".$searchSubject."%'";
                }else $query.=$qeroSea;
            }
            if(($searchEmail=$search['email'])){
                if(!(preg_match($pattern,$searchEmail))){
                $query.=" AND ost_user_email.address LIKE '%".$searchEmail."%'";
                }else $query.=$qeroSea;
            }                
            
            if(($searchTopic=$search['topicId'])){
                $query.=" AND ost_ticket.topic_id LIKE '".$searchTopic."'";
            }
            
            if(($searchDept=$search['deptId'])){
                $query.=" AND ost_ticket.dept_id LIKE '".$searchDept."'";
            }

            if(($searchPriority=$search['priorityId'])){
                $query.=" AND ost_ticket_priority.priority_id LIKE '".$searchPriority."'";
            }
            
        }
//        $query.=$query; 
        $resCount = db_query("$query");
        $rowsCount= db_num_rows($resCount);
        $statusCount['allTicket']=$rowsCount;
        $startTicket=0;
        $totalpage= ceil($rowsCount/(int)$req['numberTicket']);
        if((int)$req['page']<=$totalpage && (int)$req['page']>=1 ){
            if((int)$req['page']==1){
                $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
            }else{
                $startTicket=((int)$req['page']-1)*((int)$req['numberTicket']);
                $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
            }
        }elseif((int)$req['page']>$totalpage){
            $startTicket=((int)$totalpage-1)*((int)$req['numberTicket']);
            $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
            
            }else $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
        
        
    
    switch($req['order']){ //ORDER BY 
        case 'id':
            $qorder=' ORDER BY ost_ticket.ticket_id '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'number':
            $qorder=' ORDER BY ost_ticket.number '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'date':
            $qorder=' ORDER BY ost_ticket.created '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'subject':
            $qorder=' ORDER BY ost_ticket__cdata.subject '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'from':
           $qorder=' ORDER BY ost_user_email.address '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'priority':
           $qorder=' ORDER BY ost_ticket_priority.priority '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        case 'assigned':
           $qorder=' ORDER BY ost_staff.firstname '.$req['sort'];
            $statusCount['order']=$req['order'];
            $statusCount['sort']=$req['sort'];
            break;
        default:
            $qorder=' ORDER BY ost_ticket.created DESC '; 
            $statusCount['order']='date';
            $statusCount['sort']='DESC';
    }     
        
//        $qorder=' ORDER BY ost_ticket.created DESC ';//.$req['sort'];
        
        $query.=$qorder.$qlimit;
        $res = db_query("$query");
        $ar_res=array();
        while($row = db_fetch_array($res)){ 
            $ticket=Ticket::lookup($row['ticket_id']);
            $row['assigned']  =  $ticket->getAssignee()->name;
            $row['phone']=$ticket->getPhoneNumber();
            $lock=$ticket->getLock();
            if($lock && $lock->getStaffId()!=$thisstaff->getId()){
               $row['lock']=$lock; 
            }else $row['lock']='0'; 
            $ar_res[]=$row;
        }
        $ar_res[0]['totalPage']=$totalpage;
        $ar_res[0]['countTicket']=$statusCount;        
        $this->response(201,json_encode($ar_res));
//        $this->response(201,json_encode(date($searchId['from'])));
    }
    
    #by le tuan  
    function sendfile($ticket_id,$attach_id){
//        if(!($key=$this->requireApiKey()))
//            return $this->exerr(401, 'API key not authorized');
        
        $sql='SELECT ost_ticket_attachment.ticket_id, ost_ticket_attachment.attach_id,ost_file_chunk.file_id'
                . ', ost_file_chunk.filedata,ost_file.type, ost_file.size,ost_file.key, ost_file.name '
                . 'FROM ost_ticket_attachment INNER JOIN ost_file ON (ost_ticket_attachment.file_id=ost_file.id) '
                . 'INNER JOIN ost_file_chunk ON (ost_file.id=ost_file_chunk.file_id)'
                . 'WHERE ost_ticket_attachment.ticket_id='."'".$ticket_id."'"
                .' AND ost_ticket_attachment.attach_id='."'".$attach_id."'";
        if(($res=db_query("$sql")) && db_num_rows($res)){
            $row = db_fetch_array($res);
            $row['filedata']=  base64_encode($row['filedata']);
            $this->response(201,json_encode($row));
        }else{
            $this->response(401,json_encode('FILE not exist'));
        }
    }
    
    function status($format,$status){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $req=$this->getRequest($format);
        $arr=array();
        foreach ($req as $ticket_id){
         $arr[]= $this->statusCR($ticket_id,$status);
        }
        $this->response(201,json_encode($arr));
    }
    function statusCR($ticket_id,$status){
        $ticket=Ticket::lookup($ticket_id);
        switch($status){
            case 'close':
               if($ticket->isClosed()) {
                    return 'Ticket is already closed!';
                }elseif($ticket->close()) {
                    $msg='Ticket #'.$ticket->getNumber().' status set to CLOSED';
                    return $msg;
                    }   
                break;
            case 'reopen':
                    //if staff can close or create tickets ...then assume they can reopen.
                if($ticket->isOpen()) {
                   return 'Ticket is already open!';
                } elseif($ticket->reopen()) {
                    $msg='Ticket #' .$ticket->getNumber().' REOPENED';
                    return $msg ;
                }
                break;
        } 
    }
    function postReplyAdmin($ticket_id,$email,$format){
        global $thisstaff, $cfg;
        
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $ticket=Ticket::lookup($ticket_id);
        if(!$ticket){
             $this->response(401, 'Ticket not found');
             exit(-1);
        }
       
        $req=$this->getRequest($format);
        $req['response']=$req['message'];
        unset($req['message']);
        $classStaff= new Staff;
        if(!$classStaff->Staff($email)){
            $this->response(401, 'Staff not registry please registry to user');
             exit(-1);
        } 
        $thisstaff=$classStaff;
        $lock=$ticket->getLock();
         if($lock && $lock->getStaffId()!=$thisstaff->getId()){
            $msg='Action Denied. Ticket is locked by someone else!';
            $this->response(201, json_encode($msg));
         }
        $errors=array();
        if(($response=$ticket->postReply($req, $errors, $req['emailreply']))) {
                $msg='Reply posted successfully:';
                $ticket->reload();

                if($ticket->isClosed() && $wasOpen)
                    $ticket=null;
                else
                    // Still open -- cleanup response draft for this user
                    Draft::deleteForNamespace(
                        'ticket.response.' . $ticket->getId(),
                        $thisstaff->getId());

            } elseif(!$errors['err']) {
                $msg='Unable to post the reply. Correct the errors below and try again!';
                
            }
            $this->response(201, json_encode($msg));
    }
    function postReplyCustomer($ticket_id,$format){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        $ticket=Ticket::lookup($ticket_id);
        $req=$this->getRequest($format);
        if($ticket->getStatus()== 'closed'){
            $ticket->reopen();
        }
        if(($msgid=$ticket->postMessage($req, 'API'))) {
                $msg='Message Posted Successfully';
                 $this->response(201, json_encode($msg));     
            } else {
                $errors='Unable to post the message. Try again';
                $this->response(401, json_encode($errors));
            } 
    }
    #by le tuan
    function check($email,$format){ 
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        if($email==null){
            return $this->exerr(401, 'Email not registry, please registry to user');
        }
        $queryu='SELECT user_id FROM ost_user_email WHERE address='."'".$email."'";      
        $resu = db_query("$queryu");
        $rowu = db_fetch_array($resu);
        
        if($rowu['user_id']==null){
            return $this->exerr(401, 'Email not found');
        }
        $query='SELECT ost_ticket.ticket_id,ost_ticket.staff_id,ost_ticket.team_id,ost_ticket.number,ost_ticket.created,ost_ticket.status'
                . ',ost_ticket__cdata.subject,ost_help_topic.topic,ost_department.dept_name,'
                . 'ost_ticket_priority.priority,ost_staff.staff_id,ost_staff.firstname, ost_staff.lastname, ost_ticket.team_id'
                . ' FROM ost_ticket INNER JOIN ost_ticket__cdata ON (ost_ticket.ticket_id=ost_ticket__cdata.ticket_id)'
                . ' LEFT JOIN ost_help_topic ON (ost_ticket.topic_id=ost_help_topic.topic_id)'
                . 'INNER JOIN ost_department ON (ost_ticket.dept_id=ost_department.dept_id)'
                . 'INNER JOIN ost_ticket_priority ON (ost_ticket_priority.priority_id=ost_ticket__cdata.priority_id)'
                . 'LEFT JOIN ost_staff ON (ost_staff.staff_id=ost_ticket.staff_id)'
                . 'WHERE ost_ticket.user_id='."'".$rowu['user_id']."'"
                .' ORDER BY ost_ticket.created DESC ';
        $resR = db_query("$query");
        
        $req=$this->getRequest($format);
        $rowsCount= db_num_rows($resR);
        $startTicket=0;
        $totalpage= ceil($rowsCount/(int)$req['numberTicket']);
        if((int)$req['page']<=$totalpage && (int)$req['page']>=1 ){
            if((int)$req['page']==1){
                $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
            }else{
                $startTicket=((int)$req['page']-1)*((int)$req['numberTicket']);
                $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
            }
        }else $qlimit=' LIMIT '.$startTicket.','.(int)$req['numberTicket'];
        
        $query.=$qlimit;
        $res = db_query("$query");
        $ar_res=array();
        while($row = db_fetch_array($res)){
            $ticket=Ticket::lookup($row['ticket_id']);
            $row['assigned']  =  $ticket->getAssignee()->name;
            $row['phone']=$ticket->getPhoneNumber();           
            $ar_res[]=$row;
        }
        $ar_res[0]['totalPage']=$totalpage;
        
        $this->response(201,json_encode($ar_res));
    }
     #by le tuan
     function view($ticket_id,$format=null){
        if(!($key=$this->requireApiKey()))
            return $this->exerr(401, 'API key not authorized');
        
        $ticket=Ticket::lookup($ticket_id);
        if($format=='json'){
           $req=$this->getRequest($format);
           if($req['from'] && $req['from']=='backend'){
            global $thisstaff, $cfg;
            
            $classStaff= new Staff;
            if(!$classStaff->Staff($req['email'])){
                $this->response(401, 'Staff not registry please registry to user');
                 exit(-1);
            } 
            $thisstaff=$classStaff;
                   
            $lock=$ticket->getLock();
            if(empty($lock) && $cfg->getLockTime() && !$ticket->acquireLock($thisstaff->getId(),$cfg->getLockTime()))
            $warn='Unable to obtain a lock on the ticket';
           }
        }
        $query='SELECT ost_ticket.ticket_id, ost_ticket.user_id FROM ost_ticket '
                . 'LEFT JOIN ost_user_email ON ost_ticket.user_id=ost_user_email.user_id '
                . 'WHERE ost_user_email.address='."'".$ticket->getEmail()."'";
        $res=db_query("$query");
        $count= db_num_rows($res);
       if(!$ticket)
            return $this->exerr(401, 'Ticket not found');
        $data=array(
            'name'          =>  $ticket->getName(),
            'number'        =>  $ticket->getNumber(),
            'id'            =>  $ticket->getId(), 
            'phone'         =>  $ticket->getPhoneNumber(),
            'email'         =>  $ticket->getEmail(),
            'status'        =>  $ticket->getStatus(),
            'helptopic'     =>  $ticket->getHelpTopic(),
            'subject'       =>  $ticket->getSubject(),
            'createdate'    =>  Format::db_datetime($ticket->getCreateDate()),
            'department'    =>  $ticket->getDeptName(),
            'assigned'      =>  $ticket->getAssigned(),
            'source'        =>  $ticket->getSource(),
            'priority'      =>  $ticket->getPriority(),
            'dueDate'       =>  Format::db_datetime($ticket->getEstDueDate()),
            'lastMessage'   =>  Format::db_datetime($ticket->getLastMessageDate()),
            'lastResponse'  =>  Format::db_datetime($ticket->getLastResponseDate()),
            'countTicket'   =>  $count,
            'slaPlan'       =>  $ticket->getSLA()->ht,
        );  
        $arr_thread=$ticket->getClientThread();
        $count_thread=count($ticket->getClientThread());
        $i=0;
        for($i;$i<$count_thread;$i++){
            $tentry=$ticket->getThreadEntry($arr_thread[$i]['id']);
            $arr_thread[$i]['created']=Format::db_datetime($arr_thread[$i]['created']);
            $arr_file=$tentry->getAttachmentFile();//get info file
           
            if($arr_thread[$i]['attachments']){
             $arr_thread[$i]['file'] =$arr_file;
             }else{ $arr_thread[$i]['file']=null; }
        }
        if($lock && $lock->getStaffId()!=$thisstaff->getId()){
           $data['locked']=$lock; 
        }
        $data['thread']=$arr_thread;
        $this->response(201,json_encode($data));
    }
     
    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, 'API key not authorized');

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, "Unable to create new ticket: unknown error");
        
        $this->response(201, json_encode($ticket->getNumber()));
    }  
    /* private helper functions */

    function createTicket($data) {
        
        # Pull off some meta-data
        $alert = $data['alert'] ? $data['alert'] : true;
        $autorespond = $data['autorespond'] ? $data['autorespond'] : true;
        $data['source'] = $data['source'] ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, 'Ticket denied');
            else
                return $this->exerr(
                        400,
                        "Unable to create new ticket: validation errors:\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, "Unable to create new ticket: unknown error");
        }

        return $ticket;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        if (($thread = ThreadEntry::lookupByEmailHeaders($data))
                && $thread->postEmail($data)) {
            return $thread->getTicket();
        }
        return $this->createTicket($data);
    }
}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, 'Request failed - retry again!');
    }
}

?>
