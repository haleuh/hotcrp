<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/search.inc");
$Me->goIfInvalid();
$Me->goIfNotPrivChair();

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pcs"]) && is_string($_REQUEST["pcs"]))
    $_REQUEST["pcs"] = preg_split('/\s+/', $_REQUEST["pcs"]);
if (isset($_REQUEST["pcs"]) && is_array($_REQUEST["pcs"])) {
    $pcsel = array();
    foreach ($_REQUEST["pcs"] as $p)
	if (($p = cvtint($p)) > 0)
	    $pcsel[$p] = 1;
} else
    $pcsel = pcMembers();
if (defval($_REQUEST, "a") == "prefconflict" && !isset($_REQUEST["t"])
    && $Conf->setting("pc_seeall") > 0)
    $_REQUEST["t"] = "all";
else if ($Conf->has_managed_submissions())
    $_REQUEST["t"] = defval($_REQUEST, "t", "unm");
else
    $_REQUEST["t"] = defval($_REQUEST, "t", "s");
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]) && !isset($_REQUEST["requery"])) {
    $papersel = array();
    foreach ($_REQUEST["p"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
} else {
    $papersel = array();
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
}
sort($papersel);
if ((isset($_REQUEST["prevt"]) && isset($_REQUEST["t"]) && $_REQUEST["prevt"] != $_REQUEST["t"])
    || (isset($_REQUEST["prevq"]) && isset($_REQUEST["q"]) && $_REQUEST["prevq"] != $_REQUEST["q"])) {
    if (isset($_REQUEST["p"]) && isset($_REQUEST["assign"]))
	$Conf->infoMsg("You changed the paper search.  Please review the resulting paper list.");
    unset($_REQUEST["assign"]);
    $_REQUEST["requery"] = 1;
}
if (!isset($_REQUEST["assign"]) && !isset($_REQUEST["requery"])
    && isset($_REQUEST["default"]) && isset($_REQUEST["defaultact"])
    && ($_REQUEST["defaultact"] == "assign" || $_REQUEST["defaultact"] == "requery"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
if (!isset($_REQUEST["pctyp"]) || ($_REQUEST["pctyp"] != "all" && $_REQUEST["pctyp"] != "sel"))
    $_REQUEST["pctyp"] = "all";

// bad pairs
// load defaults from last autoassignment or save entry to default
$pcm = pcMembers();
if (!isset($_REQUEST["bpcount"]) || !ctype_digit($_REQUEST["bpcount"]))
    $_REQUEST["bpcount"] = "50";
if (!isset($_REQUEST["badpairs"]) && !isset($_REQUEST["assign"]) && !count($_POST)) {
    $x = preg_split('/\s+/', $Conf->settingText("autoassign_badpairs", ""), null, PREG_SPLIT_NO_EMPTY);
    $bpnum = 1;
    for ($i = 0; $i < count($x) - 1; $i += 2)
	if (isset($pcm[$x[$i]]) && isset($pcm[$x[$i+1]])) {
	    $_REQUEST["bpa$bpnum"] = $x[$i];
	    $_REQUEST["bpb$bpnum"] = $x[$i+1];
	    ++$bpnum;
	}
    $_REQUEST["bpcount"] = $bpnum - 1;
    if ($Conf->setting("autoassign_badpairs"))
	$_REQUEST["badpairs"] = 1;
} else if (count($_POST) && isset($_REQUEST["assign"]) && check_post()) {
    $x = array();
    for ($i = 1; $i <= $_REQUEST["bpcount"]; ++$i)
	if (defval($_REQUEST, "bpa$i") && defval($_REQUEST, "bpb$i")
	    && isset($pcm[$_REQUEST["bpa$i"]]) && isset($pcm[$_REQUEST["bpb$i"]])) {
	    $x[] = $_REQUEST["bpa$i"];
	    $x[] = $_REQUEST["bpb$i"];
	}
    if (count($x) || $Conf->settingText("autoassign_badpairs")
	|| (!isset($_REQUEST["badpairs"]) != !$Conf->setting("autoassign_badpairs")))
	$Conf->q("insert into Settings (name, value, data) values ('autoassign_badpairs', " . (isset($_REQUEST["badpairs"]) ? 1 : 0) . ", '" . sqlq(join(" ", $x)) . "') on duplicate key update data=values(data), value=values(value)");
}
// set $badpairs array
$badpairs = array();
if (isset($_REQUEST["badpairs"]))
    for ($i = 1; $i <= $_REQUEST["bpcount"]; ++$i)
	if (defval($_REQUEST, "bpa$i") && defval($_REQUEST, "bpb$i")) {
	    if (!isset($badpairs[$_REQUEST["bpa$i"]]))
		$badpairs[$_REQUEST["bpa$i"]] = array();
	    if (!isset($badpairs[$_REQUEST["bpb$i"]]))
		$badpairs[$_REQUEST["bpb$i"]] = array();
	    $badpairs[$_REQUEST["bpa$i"]][$_REQUEST["bpb$i"]] = 1;
	    $badpairs[$_REQUEST["bpb$i"]][$_REQUEST["bpa$i"]] = 1;
	}

// score selector
$scoreselector = array();
$rf = reviewForm();
if ($rf->field("overAllMerit")->displayed) { // overAllMerit comes first
    $scoreselector["+overAllMerit"] = "";
    $scoreselector["-overAllMerit"] = "";
}
foreach ($rf->forder as $f)
    if ($f->has_options) {
	$scoreselector["+" . $f->id] = "high $f->name_html scores";
	$scoreselector["-" . $f->id] = "low $f->name_html scores";
    }
$scoreselector["x"] = "(no score preference)";

$Error = array();


class Assigner {
    public $pid;
    public $cid;
    static private $assigners = array();
    static function register($n, $a) {
        assert(!@self::$assigners[$n]);
        self::$assigners[$n] = $a;
    }
    static function find($n) {
        return @self::$assigners[$n];
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
    }
    function require_pc() {
        return true;
    }
    function parse($req) {
        return true;
    }
}
class ReviewAssigner extends Assigner {
    private $type;
    private $round;
    function __construct($type) {
        $this->type = $type;
    }
    function parse($req) {
        $this->round = @$req["round"];
        return true;
    }
    function require_pc() {
        return $this->type != REVIEW_PC;
    }
    function unparse_display($pcm) {
        global $reviewTypeName, $assignprefs, $Conf;
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc) . ' ';
        if ($this->type) {
            $t .= $Conf->cacheableImage("_.gif", $reviewTypeName[$this->type], $reviewTypeName[$this->type] . " review", "ass" . $this->type . "n");
            if ($this->round)
                $t .= ' <span class="revround" title="Review round">'
                    . htmlspecialchars($this->round) . '</span>';
            $pref = @$assignprefs["$this->pid:$this->cid"];
            if ($pref !== "*" && $pref != 0)
                $t .= " <span class='asspref" . ($pref > 0 ? 1 : -1)
                    . "'>P" . decorateNumber($pref) . "</span>";
        } else
            $t .= '(clear review)';
        return $t;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
        $delta = $this->type ? 1 : -1;
        foreach (array($nrev, $nrev->pset) as $nnrev) {
            $nnrev->any[$this->cid] += $delta;
            if ($this->type == REVIEW_PRIMARY)
                $nnrev->pri[$this->cid] += $delta;
            else if ($this->type == REVIEW_SECONDARY)
                $nnrev->sec[$this->cid] += $delta;
        }
    }
    function execute($contact, $when) {
        global $Conf, $Me;
        $result = $Conf->qe("select contactId, paperId, reviewId, reviewType, reviewModified from PaperReview where paperId=$this->pid and contactId=$this->cid");
        $Me->assignPaper($this->pid, edb_orow($result), $contact, $this->type, $when);
    }
}
class LeadAssigner extends Assigner {
    private $type;
    private $isadd;
    function __construct($type, $isadd) {
        $this->type = $type;
        $this->isadd = $isadd;
    }
    function unparse_display($pcm) {
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc);
        if ($this->isadd)
            $t .= " ($this->type)";
        else
            $t .= " (clear $this->type)";
        return $t;
    }
    function execute($contact, $when) {
        global $Conf;
        if ($this->isadd)
            $Conf->qe("update Paper set " . $this->type . "ContactId=$this->cid where paperId=$this->pid");
        else
            $Conf->qe("update Paper set " . $this->type . "ContactId=0 where paperId=$this->pid and " . $this->type . "ContactId=$this->cid");
    }
}
class ConflictAssigner extends Assigner {
    private $isadd;
    function __construct($isadd) {
        $this->isadd = $isadd;
    }
    function unparse_display($pcm) {
        global $Conf;
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc) . ' ';
        if ($this->isadd)
            $t .= $Conf->cacheableImage("_.gif", "Conflict", "Conflict", "ass-1");
        else
            $t .= '(clear conflict)';
        return $t;
    }
    function execute($contact, $when) {
        global $Conf;
        if ($this->isadd)
            $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid," . CONFLICT_CHAIRMARK . ") on duplicate key update conflictType=greatest(conflictType,values(conflictType))");
        else
            $Conf->qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid and conflictType<" . CONFLICT_AUTHOR);
    }
}

Assigner::register("primary", new ReviewAssigner(REVIEW_PRIMARY));
Assigner::register("secondary", new ReviewAssigner(REVIEW_SECONDARY));
Assigner::register("review", new ReviewAssigner(REVIEW_PC));
Assigner::register("noreview", new ReviewAssigner(0));
Assigner::register("lead", new LeadAssigner("lead", true));
Assigner::register("nolead", new LeadAssigner("lead", false));
Assigner::register("shepherd", new LeadAssigner("shepherd", true));
Assigner::register("noshepherd", new LeadAssigner("shepherd", false));
Assigner::register("conflict", new ConflictAssigner(true));
Assigner::register("noconflict", new ConflictAssigner(false));

class AssignmentSet {
    private $assigners = array();
    private $filename;
    private $errors = array();

    private function error($lineno, $message) {
        if ($this->filename)
            $this->errors[] = '<span class="lineno">'
                . htmlspecialchars($this->filename)
                . ':' . $lineno . ':</span> ' . $message;
        else
            $this->errors[] = $message;
        return false;
    }

    private static function req_user_html($req) {
        return Text::user_html_nolink(@$req["firstName"], @$req["lastName"], @$req["email"]);
    }

    private static function contacts_by($what) {
        $cb = array();
        foreach (edb_orows($Conf->qe("select contactId, email, firstName, lastName, roles from ContactInfo")) as $c)
            $cb[$c->$what] = $c;
        return $cb;
    }

    function parse($text, $filename = null, $default_action = null) {
        $this->filename = $filename;

        $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("%#");
        if (!($req = $csv->next()))
            return $this->error($csv->lineno(), "empty file");

        // check for header
        if (array_search("action", $req) !== false
            || array_search("paper", $req) !== false)
            $csv->set_header($req);
        else {
            if (count($req) == 2)
                $csv->set_header(array("paper", "user"));
            else
                $csv->set_header(array("paper", "action", "user", "round"));
            $csv->unshift($req);
        }
        if (array_search("action", $csv->header()) === false
            && !$default_action)
            return $this->error($csv->lineno(), "“action” column missing");
        if (array_search("paper", $csv->header()) === false)
            return $this->error($csv->lineno(), "“paper” column missing");

        // set up PC mappings
        $pcm = pcMembers();
        $pc_by_email = array();
        foreach ($pcm as $id => $pc)
            $pc_by_email[$pc->email] = $pc;
        $contact_by_email = null;

        // parse file
        while (($req = $csv->next()) !== false) {
            // check paper
            $pid = @trim($req["paper"]);
            if ($pid == "" || !ctype_digit($pid)) {
                $this->error($csv->lineno(), "bad paper column");
                continue;
            }

            // check action
            if (($action = @$req["action"]) === null)
                $action = $default_action;
            $action = strtolower(trim($action));
            if (!($assigner = Assigner::find($action))) {
                $this->error($csv->lineno(), "unknown action “" . htmlspecialchars($req["action"]) . "”");
                continue;
            }
            $assigner = clone $assigner;
            $assigner->pid = intval($pid);

            // clean user parts
            foreach (array("first" => "firstName", "last" => "lastName")
                     as $k1 => $k2)
                if (isset($req[$k1]) && !isset($req[$k2]))
                    $req[$k2] = $req[$k1];
            if (!isset($req["email"]) && isset($req["user"])) {
                $a = Text::split_name($req["user"], true);
                foreach (array("firstName", "lastName", "email") as $i => $k)
                    if ($a[$i])
                        $req[$k] = $a[$i];
            } else if (isset($req["name"]) || isset($req["user"])) {
                $a = Text::split_name($req[@$req["name"] ? "name" : "user"]);
                foreach (array("firstName", "lastName") as $i => $n)
                    if ($a[$i] && !isset($req[$k]))
                        $req[$k] = $a[$i];
            }

            // check user
            $email = @trim($req["email"]);
            if ($email && isset($pc_by_email[$email]))
                $assigner->cid = $pc_by_email[$email]->contactId;
            else if ($assigner->require_pc()) {
                $assigner->cid = matchContact($pcm, @$req["firstName"], @$req["lastName"], $email);
                if ($assigner->cid == -2)
                    $this->error($csv->lineno(), "no PC member matches “" . self::req_user_html($req) . "”");
                else if ($assigner->cid <= 0)
                    $this->error($csv->lineno(), "“" . self::req_user_html($req) . "” matches more than one PC member, give a full email address to disambiguate");
                if ($assigner->cid <= 0)
                    continue;
            } else {
                if (!$contact_by_email)
                    $contact_by_email = self::contacts_by("email");
                if (!$email) {
                    $this->error($csv->lineno(), "missing email address");
                    continue;
                } else if (isset($contact_by_email[$email]))
                    $assigner->cid = $contact_by_email[$email]->contactId;
                else if (!validateEmail($email)) {
                    $this->error($csv->lineno(), "email address “" . htmlspecialchars($email) . "” is invalid");
                    continue;
                } else {
                    $assigner->cid = "new";
                    foreach (array("email", "firstName", "lastName") as $x)
                        $assigner->$x = @$req[$x];
                }
            }

            // assign other
            if ($assigner->parse($req))
                $this->assigners[] = $assigner;
            else
                $this->error($csv->lineno(), "parse error for action “" . htmlspecialchars($action) . "”");
        }
    }

    function echo_unparse_display() {
        global $Conf, $Me, $papersel;
        $pcm = pcMembers();
        $nrev = self::count_reviews();
        $nrev->pset = self::count_reviews($papersel);
        $countbycid = array();

        $bypaper = array();
        foreach ($this->assigners as $assigner)
            if (($text = $assigner->unparse_display($pcm))) {
                arrayappend($bypaper[$assigner->pid], (object)
                            array("text" => $text,
                                  "sorter" => $pcm[$assigner->cid]->sorter));
                $assigner->account($countbycid, $nrev);
            }

        AutoassignmentPaperColumn::$header = "Proposed assignment";
        AutoassignmentPaperColumn::$info = array();
        PaperColumn::register(new AutoassignmentPaperColumn);
        foreach ($bypaper as $pid => $list) {
            uasort($list, "_sort_pcMember");
            $t = "";
            foreach ($list as $x)
                $t .= ($t ? ", " : "") . '<span class="nowrap">'
                    . $x->text . '</span>';
            if (isset($conflictedPapers[$pid])) {
                if ($conflictedPapers[$pid])
                    $t = '<em>Hidden for conflict</em>';
                else
                    $t = PaperList::wrapChairConflict($t);
            }
            AutoassignmentPaperColumn::$info[$pid] = $t;
        }

        ksort(AutoassignmentPaperColumn::$info);
        $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => join(" ", array_keys(AutoassignmentPaperColumn::$info))));
        $plist = new PaperList($search);
        $plist->display .= " reviewers ";
        echo $plist->text("reviewers", $Me);

	echo "<div class='g'></div>";
	echo "<h3>Assignment summary</h3>\n";
	echo '<table class="pctb"><tr><td class="pctbcolleft"><table>';
	$colorizer = new Tagger;
	$pcdesc = array();
	foreach ($pcm as $cid => $pc) {
	    $nnew = @+$countbycid[$cid];
	    $color = $colorizer->color_classes($pc->contactTags);
	    $color = ($color ? ' class="' . $color . '"' : "");
	    $c = "<tr$color><td class='pctbname pctbl'>"
		. Text::name_html($pc)
		. ": " . plural($nnew, "assignment")
		. "</td></tr><tr$color><td class='pctbnrev pctbl'>"
		. review_count_report($nrev, $pc,
                                      $nnew ? "After assignment:&nbsp;" : "");
	    $pcdesc[] = $c . "</td></tr>\n";
	}
	$n = intval((count($pcdesc) + 2) / 3);
	for ($i = 0; $i < count($pcdesc); $i++) {
	    if (($i % $n) == 0 && $i)
		echo "</table></td><td class='pctbcolmid'><table>";
	    echo $pcdesc[$i];
	}
	echo "</table></td></tr></table>\n";
    }

    function execute() {
        global $Conf, $Now;
        if (count($this->errors)) {
            $Conf->errorMsg('Errors in assignments: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors) . '</p></div> Due to these errors, the assignments were ignored.');
            return false;
        } else if (!count($this->assigners)) {
            $Conf->warnMsg('Nothing to assign.');
            return false;
        }

        $Conf->qe("lock tables ContactInfo read, PCMember read, ChairAssistant read, Chair read, PaperReview write, PaperReviewRefused write, Paper write, PaperConflict write, ActionLog write, Settings write, PaperTag write");

        $pcm = pcMembers();
        $contact_by_id = null;
        $contact_by_email = array();
        foreach ($this->assigners as $assigner) {
            if ($assigner->cid <= 0) {
                if (!($c = @$contact_by_email[$assigner->email])) {
                    $c = new Contact;
                    $c->load_by_email($assigner->email, array("firstName" => @$assigner->firstName, "lastName" => @$assigner->lastName), false);
                    // XXX assume that never fails
                    $contact_by_email[$assigner->email] = $c;
                }
                $assigner->cid = $c->contactId;
            } else if (isset($pcm[$assigner->cid]))
                $c = $pcm[$assigner->cid];
            else {
                if (!$contact_by_id)
                    $contact_by_id = self::contacts_by("contactId");
                $c = $contact_by_id[$assigner->cid];
            }
            $assigner->execute($c, $Now);
        }

        // confirmation message
        if ($Conf->sversion >= 46 && $Conf->setting("pcrev_assigntime") == $Now)
            $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
        else
            $Conf->confirmMsg("Assignments saved!");

        // clean up
        $Conf->qe("unlock tables");
        $Conf->updateRevTokensSetting(false);
        $Conf->update_paperlead_setting();
        return true;
    }

    static function count_reviews($papers = null) {
        global $Conf;
        $nrev = (object) array("any" => array(), "pri" => array(), "sec" => array());
        foreach (pcMembers() as $id => $pc)
            $nrev->any[$id] = $nrev->pri[$id] = $nrev->sec[$id] = 0;

        $q = "select pc.contactId, group_concat(r.reviewType separator '')
		from PCMember pc
		left join PaperReview r on (r.contactId=pc.contactId)\n\t\t";
        if (!$papers)
            $q .= "left join Paper p on (p.paperId=r.paperId)
		where p.paperId is null or p.timeWithdrawn<=0";
        else
            $q .= "where r.paperId" . sql_in_numeric_set($papers);
        $result = $Conf->qe($q . " group by pc.contactId",
                            "while counting reviews");
        while (($row = edb_row($result))) {
            $nrev->any[$row[0]] = strlen($row[1]);
            $nrev->pri[$row[0]] = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
            $nrev->sec[$row[0]] = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
        }
        return $nrev;
    }
}

function _review_count_link($count, $word, $pl, $prefix, $pc, $suffix) {
    $word = $pl ? plural($count, $word) : $count . "&nbsp;" . $word;
    if ($count == 0)
	return $word;
    return "<a class=\"qq\" href=\"" . hoturl("search", "q=" . urlencode("$prefix:$pc->email$suffix"))
	. "\">" . $word . "</a>";
}

function _review_count_report_one($nrev, $pc, $xq) {
    $na = defval($nrev->any, $pc->contactId, 0);
    $np = defval($nrev->pri, $pc->contactId, 0);
    $ns = defval($nrev->sec, $pc->contactId, 0);
    $t = _review_count_link($na, "review", true, "re", $pc, $xq);
    $x = array();
    if ($np != $na)
	$x[] = _review_count_link($np, "primary", false, "pri", $pc, $xq);
    if ($ns != 0 && $ns != $na && $np + $ns != $na)
	$x[] = _review_count_link($np, "secondary", false, "sec", $pc, $xq);
    if (count($x))
	$t .= " (" . join(", ", $x) . ")";
    return $t;
}

function review_count_report($nrev, $pc, $prefix) {
    global $papersel, $Conf;
    $row1 = _review_count_report_one($nrev, $pc, "");
    if (defval($nrev->pset->any, $pc->contactId, 0) != defval($nrev->any, $pc->contactId, 0)) {
	$row2 = "<span class=\"dim\">$row1 total</span>";
	$row1 = _review_count_report_one($nrev->pset, $pc, " " . join(" ", $papersel)) . " in selection";
    } else
	$row2 = "";
    if ($row2 != "" && $prefix)
	return "<table><tr><td>$prefix</td><td>$row1</td></tr><tr><td></td><td>$row2</td></tr></table>";
    else if ($row2 != "")
	return $row1 . "<br />" . $row2;
    else
	return $prefix . $row1;
}

function conflictedPapers() {
    global $Conf, $Me;
    if ($Conf->sversion >= 51)
        $result = $Conf->qe("select p.paperId, p.managerContactId from Paper p join PaperConflict c on (c.paperId=p.paperId) where c.conflictType!=0 and c.contactId=$Me->cid");
    else
        $result = $Conf->qe("select paperId, 0 from PaperConflict where conflictType!=0 and contactId=$Me->cid");
    $confs = array();
    while (($row = edb_row($result)))
	$confs[$row[0]] = $row[1];
    return $confs;
}

if (!function_exists("array_fill_keys")) {
    function array_fill_keys($a, $v) {
	$x = array();
	foreach ($a as $k)
	    $x[$k] = $v;
	return $x;
    }
}

function checkRequest(&$atype, &$reviewtype, $save) {
    global $Error, $Conf;

    $atype = $_REQUEST["a"];
    $atype_review = ($atype == "rev" || $atype == "revadd" || $atype == "revpc");
    if (!$atype_review && $atype != "lead" && $atype != "shepherd"
	&& $atype != "prefconflict" && $atype != "clear") {
	$Error["ass"] = true;
	return $Conf->errorMsg("Malformed request!");
    }

    if ($atype_review) {
	$reviewtype = defval($_REQUEST, $atype . "type", "");
	if ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY
	    && $reviewtype != REVIEW_PC) {
	    $Error["ass"] = true;
	    return $Conf->errorMsg("Malformed request!");
	}
    }
    if ($atype == "clear")
	$reviewtype = defval($_REQUEST, "cleartype", "");
    if ($atype == "clear"
	&& ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY
	    && $reviewtype != REVIEW_PC
	    && $reviewtype !== "conflict" && $reviewtype !== "lead"
	    && $reviewtype !== "shepherd")) {
	$Error["clear"] = true;
	return $Conf->errorMsg("Malformed request!");
    }
    $_REQUEST["rev_roundtag"] = defval($_REQUEST, "rev_roundtag", "");
    if ($_REQUEST["rev_roundtag"] == "(None)")
	$_REQUEST["rev_roundtag"] = "";
    if ($atype_review && $_REQUEST["rev_roundtag"] != ""
	&& !preg_match('/^[a-zA-Z0-9]+$/', $_REQUEST["rev_roundtag"])) {
	$Error["rev_roundtag"] = true;
	return $Conf->errorMsg("The review round must contain only letters and numbers.");
    }

    if ($save)
	/* no check */;
    else if ($atype == "rev" && rcvtint($_REQUEST["revct"], -1) <= 0) {
	$Error["rev"] = true;
	return $Conf->errorMsg("Enter the number of reviews you want to assign.");
    } else if ($atype == "revadd" && rcvtint($_REQUEST["revaddct"], -1) <= 0) {
	$Error["revadd"] = true;
	return $Conf->errorMsg("You must assign at least one review.");
    } else if ($atype == "revpc" && rcvtint($_REQUEST["revpcct"], -1) <= 0) {
	$Error["revpc"] = true;
	return $Conf->errorMsg("You must assign at least one review.");
    }

    return true;
}

function noBadPair($pc, $pid, $prefs) {
    global $badpairs;
    foreach ($badpairs[$pc] as $opc => $val)
	if (defval($prefs[$opc], $pid, 0) < -1000000)
	    return false;
    return true;
}

function doAssign() {
    global $Conf, $papersel, $pcsel, $assignments, $assignprefs, $badpairs, $scoreselector;

    // check request
    if (!checkRequest($atype, $reviewtype, false))
	return false;

    // fetch PC members, initialize preferences and results arrays
    $pcm = pcMembers();
    $prefs = array();
    foreach ($pcm as $pc)
	$prefs[$pc->contactId] = array();
    $assignments = array("paper,action,email,round");
    $assignprefs = array();

    // choose PC members to use for assignment
    if ($_REQUEST["pctyp"] == "sel") {
	$pck = array_keys($pcm);
	foreach ($pck as $pcid)
	    if (!isset($pcsel[$pcid]))
		unset($pcm[$pcid]);
	if (!count($pcm)) {
	    $Conf->errorMsg("Select one or more PC members to assign.");
	    return null;
	}
    }

    // prefconflict is a special case
    if ($atype == "prefconflict") {
	$papers = array_fill_keys($papersel, 1);
	$result = $Conf->qe($Conf->preferenceConflictQuery($_REQUEST["t"], ""), "while fetching preferences");
	while (($row = edb_row($result))) {
	    if (!isset($papers[$row[0]]) || !isset($pcm[$row[1]]))
		continue;
            $assignments[] = "$row[0],conflict," . $pcm[$row[1]]->email;
	    $assignprefs["$row[0]:$row[1]"] = $row[2];
	}
	if (count($assignments) == 1) {
	    $Conf->warnMsg("Nothing to assign.");
            $assignments = null;
	}
	return;
    }

    // clear is another special case
    if ($atype == "clear") {
	$papers = array_fill_keys($papersel, 1);
        $action = null;
	if ($reviewtype == REVIEW_PRIMARY || $reviewtype == REVIEW_SECONDARY
	    || $reviewtype == REVIEW_PC) {
	    $q = "select paperId, contactId from PaperReview where reviewType=" . $reviewtype;
            $action = "noreview";
	} else if ($reviewtype === "conflict") {
	    $q = "select paperId, contactId from PaperConflict where conflictType>0 and conflictType<" . CONFLICT_AUTHOR;
            $action = "noconflict";
	} else if ($reviewtype === "lead" || $reviewtype === "shepherd") {
	    $q = "select paperId, ${reviewtype}ContactId from Paper where ${reviewtype}ContactId!=0";
            $action = "no" . $reviewtype;
        }
	$result = $Conf->qe($q, "while checking clearable assignments");
	while (($row = edb_row($result))) {
	    if (!isset($papers[$row[0]]) || !isset($pcm[$row[1]]))
		continue;
            $assignments[] = "$row[0],$action," . $pcm[$row[1]]->email;
	    $assignprefs["$row[0]:$row[1]"] = "*";
	}
	if (count($assignments) == 1) {
	    $Conf->warnMsg("Nothing to assign.");
	    $assignments = null;
	}
	return;
    }

    // prepare to balance load
    $load = array_fill_keys(array_keys($pcm), 0);
    if (defval($_REQUEST, "balance", "new") != "new" && $atype != "revpc") {
	if ($atype == "rev" || $atype == "revadd")
	    $result = $Conf->qe("select PCMember.contactId, count(reviewId)
		from PCMember left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.reviewType=$reviewtype)
		group by PCMember.contactId", "while counting reviews");
	else
	    $result = $Conf->qe("select PCMember.contactId, count(paperId)
		from PCMember left join Paper on (Paper.${atype}ContactId=PCMember.contactId)
		where not (paperId in (" . join(",", $papersel) . "))
		group by PCMember.contactId", "while counting leads");
	while (($row = edb_row($result)))
	    $load[$row[0]] = $row[1] + 0;
    }

    // get preferences
    if (($atype == "lead" || $atype == "shepherd")
	&& isset($_REQUEST["${atype}score"])
	&& isset($scoreselector[$_REQUEST["${atype}score"]])) {
	$score = $_REQUEST["${atype}score"];
	if ($score == "x")
	    $score = "1";
	else
	    $score = "PaperReview." . substr($score, 1);
    } else
	$score = "PaperReview.overAllMerit";
    $result = $Conf->qe("select Paper.paperId, PCMember.contactId,
	coalesce(PaperConflict.conflictType, 0) as conflictType,
	coalesce(PaperReviewPreference.preference, 0) as preference,
	coalesce(PaperReview.reviewType, 0) as reviewType,
	coalesce(PaperReview.reviewSubmitted, 0) as reviewSubmitted,
	coalesce($score, 0) as reviewScore,
	topicInterestScore,
	coalesce(PRR.contactId, 0) as refused
	from Paper join PCMember
	left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PCMember.contactId=PaperConflict.contactId)
	left join PaperReviewPreference on (Paper.paperId=PaperReviewPreference.paperId and PCMember.contactId=PaperReviewPreference.contactId)
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PCMember.contactId=PaperReview.contactId)
	left join (select paperId, PCMember.contactId,
		sum(if(interest=2,2,interest-1)) as topicInterestScore
		from PaperTopic join PCMember
		join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId)
		group by paperId, PCMember.contactId) as PaperTopics on (Paper.paperId=PaperTopics.paperId and PCMember.contactId=PaperTopics.contactId)
	left join PaperReviewRefused PRR on (Paper.paperId=PRR.paperId and PCMember.contactId=PRR.contactId)
	where Paper.paperId" . sql_in_numeric_set($papersel) . "
	group by Paper.paperId, PCMember.contactId");

    if ($atype == "rev" || $atype == "revadd" || $atype == "revpc") {
	while (($row = edb_orow($result))) {
	    $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
	    if ($row->conflictType > 0 || $row->reviewType > 0
		|| $row->refused > 0)
		$prefs[$row->contactId][$row->paperId] = -1000001;
	    else
		$prefs[$row->contactId][$row->paperId] = max($row->preference, -1000) + ($row->topicInterestScore / 100);
	}
    } else {
	$scoredir = (substr(defval($_REQUEST, "${atype}score", "x"), 0, 1) == "-" ? -1 : 1);
	// First, collect score extremes
	$scoreextreme = array();
	$rows = array();
	while (($row = edb_orow($result))) {
	    $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
	    if ($row->conflictType > 0 || $row->reviewType == 0
		|| $row->reviewSubmitted == 0 || $row->reviewScore == 0)
		/* ignore row */;
	    else {
		if (!isset($scoreextreme[$row->paperId])
		    || $scoredir * $row->reviewScore > $scoredir * $scoreextreme[$row->paperId])
		    $scoreextreme[$row->paperId] = $row->reviewScore;
		$rows[] = $row;
	    }
	}
	// Then, collect preferences; ignore score differences farther
	// than 1 score away from the relevant extreme
	foreach ($rows as $row) {
	    $scoredifference = $scoredir * ($row->reviewScore - $scoreextreme[$row->paperId]);
	    if ($scoredifference >= -1)
		$prefs[$row->contactId][$row->paperId] = max($scoredifference * 1001 + max(min($row->preference, 1000), -1000) + ($row->topicInterestScore / 100), -1000000);
	}
	$badpairs = array();	// bad pairs only relevant for reviews,
				// not discussion leads or shephers
	unset($rows);		// don't need the memory any more
    }

    // sort preferences
    foreach ($pcm as $pc) {
	arsort($prefs[$pc->contactId]);
	reset($prefs[$pc->contactId]);
    }

    // get papers
    $papers = array();
    $loadlimit = null;
    if ($atype == "revadd")
	$papers = array_fill_keys($papersel, rcvtint($_REQUEST["revaddct"]));
    else if ($atype == "revpc") {
	$loadlimit = rcvtint($_REQUEST["revpcct"]);
	$papers = array_fill_keys($papersel, ceil((count($pcm) * $loadlimit) / count($papersel)));
    } else if ($atype == "rev") {
	$papers = array_fill_keys($papersel, rcvtint($_REQUEST["revct"]));
	$result = $Conf->qe("select paperId, count(reviewId) from PaperReview where reviewType=$reviewtype group by paperId", "while counting reviews");
	while (($row = edb_row($result)))
	    if (isset($papers[$row[0]]))
		$papers[$row[0]] -= $row[1];
    } else if ($atype == "lead" || $atype == "shepherd") {
	$papers = array();
	$xpapers = array_fill_keys($papersel, 1);
	$result = $Conf->qe("select paperId from Paper where ${atype}ContactId=0", "while selecting reviews");
	while (($row = edb_row($result)))
	    if (isset($xpapers[$row[0]]))
		$papers[$row[0]] = 1;
    } else
	assert(false);

    // check action
    if ($atype == "lead" || $atype == "shepherd")
        $action = $atype;
    else if ($reviewtype == REVIEW_PRIMARY)
        $action = "primary";
    else if ($reviewtype == REVIEW_SECONDARY)
        $action = "secondary";
    else
        $action = "review";
    if ($atype != "lead" && $atype != "shepherd" && $_REQUEST["rev_roundtag"])
        $round = "," . $_REQUEST["rev_roundtag"];
    else
        $round = "";

    // now, loop forever
    $pcids = array_keys($pcm);
    $progress = false;
    while (count($pcm)) {
	// choose a pc member at random, equalizing load
	$pc = null;
	foreach ($pcm as $pcx)
	    if ($pc == null || $load[$pcx->contactId] < $load[$pc]) {
		$numminpc = 0;
		$pc = $pcx->contactId;
	    } else if ($load[$pcx->contactId] == $load[$pc]) {
		$numminpc++;
		if (mt_rand(0, $numminpc) == 0)
		    $pc = $pcx->contactId;
	    }

	// traverse preferences in descending order until encountering an
	// assignable paper
	while (($pid = key($prefs[$pc])) !== null) {
	    $pref = current($prefs[$pc]);
	    next($prefs[$pc]);
	    if ($pref >= -1000000 && isset($papers[$pid]) && $papers[$pid] > 0
		&& (!isset($badpairs[$pc]) || noBadPair($pc, $pid, $prefs))) {
		// make assignment
		$assignments[] = "$pid,$action," . $pcx->email . $round;
		$prefs[$pc][$pid] = -1000001;
		$papers[$pid]--;
		$load[$pc]++;
		break;
	    }
	}

	// if have exhausted preferences, remove pc member
	if ($pid === null || $load[$pc] === $loadlimit)
	    unset($pcm[$pc]);
    }

    // check for unmade assignments
    ksort($papers);
    $badpids = array();
    foreach ($papers as $pid => $n)
	if ($n > 0)
	    $badpids[] = $pid;
    if ($badpids && $atype != "revpc") {
	$b = array();
	$pidx = join("+", $badpids);
	foreach ($badpids as $pid)
	    $b[] = "<a href='" . hoturl("assign", "p=$pid&amp;list=$pidx") . "'>$pid</a>";
        if ($atype == "rev" || $atype == "revadd")
            $x = ", possibly because of conflicts or previously declined reviews in the PC members you selected";
        else if ($_REQUEST["pctyp"] == "sel")
            $x = ", possibly because you haven’t selected all PC members";
        else
            $x = "";
	$y = (count($b) > 1 ? " (<a class='nowrap' href='" . hoturl("search", "q=$pidx") . "'>list them</a>)" : "");
	$Conf->warnMsg("I wasn’t able to complete the assignment$x.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . $y . ".");
    }
    if (count($assignments) == 1) {
	$Conf->warnMsg("Nothing to assign.");
	$assignments = null;
    }
}

if (isset($_REQUEST["assign"]) && isset($_REQUEST["a"])
    && isset($_REQUEST["pctyp"]) && check_post())
    doAssign();
else if (isset($_REQUEST["saveassign"])
	 && isset($_REQUEST["assignment"]) && check_post()) {
    $assignset = new AssignmentSet;
    $assignset->parse($_REQUEST["assignment"]);
    $assignset->execute();
}


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", hoturl("autoassign"), true);
$abar .= actionTab("Manual", hoturl("manualassign"), false);
$abar .= actionTab("Upload", hoturl("bulkassign"), false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "autoassign", $abar);


function doRadio($name, $value, $text, $extra = null) {
    if (($checked = (!isset($_REQUEST[$name]) || $_REQUEST[$name] == $value)))
	$_REQUEST[$name] = $value;
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    echo Ht::radio($name, $value, $checked, $extra), "&nbsp;";
    if ($text != "")
	echo Ht::label($text, "${name}_$value");
}

function doSelect($name, $opts, $extra = null) {
    if (!isset($_REQUEST[$name]))
	$_REQUEST[$name] = key($opts);
    echo Ht::select($name, $opts, $_REQUEST[$name], $extra);
}

function divClass($name) {
    global $Error;
    return "<div" . (isset($Error[$name]) ? " class='error'" : "") . ">";
}


// Help list
$helplist = "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='" . hoturl("autoassign") . "' class='q'><strong>Automatic</strong></a></li>
 <li><a href='" . hoturl("manualassign") . "'>Manual by PC member</a></li>
 <li><a href='" . hoturl("assign") . "'>Manual by paper</a></li>
 <li><a href='" . hoturl("bulkassign") . "'>Upload</a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt><img class='ass" . REVIEW_PRIMARY . "' src='images/_.gif' alt='Primary' /> Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt><img class='ass" . REVIEW_SECONDARY . "' src='images/_.gif' alt='Secondary' /> Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt><img class='ass" . REVIEW_PC . "' src='images/_.gif' alt='PC' /> Optional</dt><dd>May be declined</dd></dl>
</div></div>\n";


class AutoassignmentPaperColumn extends PaperColumn {
    static $header;
    static $info;
    public function __construct() {
        parent::__construct("autoassignment", Column::VIEW_ROW,
                            array("cssname" => "autoassignment"));
    }
    public function header($pl, $row, $ordinal) {
        return self::$header;
    }
    public function content_empty($pl, $row) {
        return !isset(self::$info[$row->paperId]);
    }
    public function content($pl, $row) {
        return self::$info[$row->paperId];
    }
}

if (isset($assignments) && count($assignments) > 0) {
    echo divClass("propass"), "<h3>Proposed assignment</h3>";
    $helplist = "";
    $Conf->infoMsg("If this assignment looks OK to you, select “Save assignment” to apply it.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as “P#”.");

    $assignset = new AssignmentSet;
    $assignset->parse(join("\n", $assignments));
    $assignset->echo_unparse_display();

    echo "<div class='g'></div>",
	"<form method='post' action='", hoturl_post("autoassign"), "' accept-charset='UTF-8'><div class='aahc'><div class='aa'>\n",
	"<input type='submit' name='saveassign' value='Save assignment' />\n",
	"&nbsp;<input type='submit' name='cancel' value='Cancel' />\n";
    foreach (array("t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "bpcount", "rev_roundtag") as $t)
	if (isset($_REQUEST[$t]))
	    echo "<input type='hidden' name='$t' value=\"", htmlspecialchars($_REQUEST[$t]), "\" />\n";
    echo "<input type='hidden' name='pcs' value='", join(" ", array_keys($pcsel)), "' />\n";
    for ($i = 1; $i <= 20; $i++) {
	if (defval($_REQUEST, "bpa$i"))
	    echo "<input type='hidden' name='bpa$i' value=\"", htmlspecialchars($_REQUEST["bpa$i"]), "\" />\n";
	if (defval($_REQUEST, "bpb$i"))
	    echo "<input type='hidden' name='bpb$i' value=\"", htmlspecialchars($_REQUEST["bpb$i"]), "\" />\n";
    }
    echo "<input type='hidden' name='p' value=\"", join(" ", $papersel), "\" />\n";

    // save the assignment
    echo '<input type="hidden" name="assignment" value="',
        join("\n", $assignments), '" />', "\n";

    echo "</div></div></form></div>\n";
    $Conf->footer();
    exit;
}

echo "<form method='post' action='", hoturl_post("autoassign"), "' accept-charset='UTF-8'><div class='aahc'>", $helplist,
    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
    "<input class='hidden' type='submit' name='default' value='1' />";

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($_REQUEST["q"]))
    $_REQUEST["q"] = join(" ", $papersel);
if ($Conf->has_managed_submissions())
    $tOpt = array("unm" => "Unmanaged submissions",
                  "s" => "All submissions");
else
    $tOpt = array("s" => "Submitted papers");
$tOpt["acc"] = "Accepted papers";
$tOpt["und"] = "Undecided papers";
$tOpt["all"] = "All papers";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "s";
$q = ($_REQUEST["q"] == "" ? "(All)" : $_REQUEST["q"]);
echo "<input id='autoassignq' class='textlite temptextoff' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"autosub('requery',this)\" onchange='highlightUpdate(\"requery\")' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    Ht::select("t", $tOpt, $_REQUEST["t"], array("onchange" => "highlightUpdate(\"requery\")")),
    " &nbsp; <input id='requery' name='requery' type='submit' value='List' />\n";
$Conf->footerScript("mktemptext('autoassignq','(All)')");
if (isset($_REQUEST["requery"]) || isset($_REQUEST["prevpap"])) {
    echo "<br /><span class='hint'>Assignments will apply to the selected papers.</span>
<div class='g'></div>";

    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $plist = new PaperList($search);
    $plist->display .= " reviewers ";
    $plist->papersel = array_fill_keys($papersel, 1);
    foreach (preg_split('/\s+/', defval($_REQUEST, "prevpap")) as $p)
	if (!isset($plist->papersel[$p]))
	    $plist->papersel[$p] = 0;
    echo $plist->text("reviewersSel", $Me, array("nofooter" => true));
    echo "<input type='hidden' name='prevt' value=\"", htmlspecialchars($_REQUEST["t"]), "\" />",
	"<input type='hidden' name='prevq' value=\"", htmlspecialchars($_REQUEST["q"]), "\" />";
    if ($plist->ids)
	echo "<input type='hidden' name='prevpap' value=\"", htmlspecialchars(join(" ", $plist->ids)), "\" />";
}
echo "</div>\n";
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";


// action
echo divClass("ass"), "<h3>Action</h3>", divClass("rev");
doRadio("a", "rev", "Ensure each paper has <i>at least</i>");
echo "&nbsp; <input type='text' class='textlite' name='revct' value=\"", htmlspecialchars(defval($_REQUEST, "revct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; ";
doSelect("revtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s)</div>\n";

echo divClass("revadd");
doRadio("a", "revadd", "Assign");
echo "&nbsp; <input type='text' class='textlite' name='revaddct' value=\"", htmlspecialchars(defval($_REQUEST, "revaddct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; ",
    "<i>additional</i>&nbsp; ";
doSelect("revaddtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) per paper</div>\n";

echo divClass("revpc");
doRadio("a", "revpc", "Assign each PC member");
echo "&nbsp; <input type='text' class='textlite' name='revpcct' value=\"", htmlspecialchars(defval($_REQUEST, "revpcct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; additional&nbsp; ";
doSelect("revpctype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) from this paper selection</div>\n";

// Review round
echo divClass("rev_roundtag");
echo "<input style='visibility: hidden' type='radio' class='cb' name='a' value='rev_roundtag' disabled='disabled' />&nbsp;";
echo "Review round: &nbsp;";
$rev_roundtag = defval($_REQUEST, "rev_roundtag", $Conf->settingText("rev_roundtag"));
if (!$rev_roundtag)
    $rev_roundtag = "(None)";
echo "<input id='rev_roundtag' class='textlite temptextoff' type='text' size='15' name='rev_roundtag' value=\"",
    htmlspecialchars($rev_roundtag),
    "\" onfocus=\"autosub('assign',this)\" />",
    " &nbsp;<a class='hint' href='", hoturl("help", "t=revround"), "'>What is this?</a></div>
<div class='g'></div>\n";
$Conf->footerScript("mktemptext('rev_roundtag','(None)')");

doRadio('a', 'prefconflict', 'Assign conflicts when PC members have review preferences of &minus;100 or less');
echo "<br />\n";

doRadio('a', 'lead', 'Assign discussion lead from reviewers, preferring&nbsp; ');
doSelect('leadscore', $scoreselector);
echo "<br />\n";

doRadio('a', 'shepherd', 'Assign shepherd from reviewers, preferring&nbsp; ');
doSelect('shepherdscore', $scoreselector);

echo "<div class='g'></div>", divClass("clear");
doRadio('a', 'clear', 'Clear all &nbsp;');
doSelect('cleartype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"));
echo " &nbsp;assignments for selected papers and PC members";
echo "</div></div>\n";


// PC
//echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";

echo "<h3>PC members</h3><table><tr><td>";
doRadio("pctyp", "all", "");
echo "</td><td>", Ht::label("Use entire PC", "pctyp_all"), "</td></tr>\n";

echo "<tr><td>";
doRadio('pctyp', 'sel', '');
echo "</td><td>", Ht::label("Use selected PC members:", "pctyp_sel"), " &nbsp; (select ";
$pctyp_sel = array(array("all", 1, "all"), array("none", 0, "none"));
$pctags = pcTags();
if (count($pctags)) {
    $tagsjson = array();
    foreach (pcMembers() as $pc)
	if ($pc->contactTags)
	    $tagsjson[] = "\"$pc->contactId\":\"" . strtolower($pc->contactTags) . "\"";
    $Conf->footerScript("pc_tags_json={" . join(",", $tagsjson) . "};");
    foreach ($pctags as $tagname => $pctag)
	$pctyp_sel[] = array($pctag, "pc_tags_members(\"$tagname\")", "“${pctag}”&nbsp;tag");
}
$sep = "";
foreach ($pctyp_sel as $pctyp) {
    echo $sep, "<a href='#pc_", $pctyp[0], "' onclick='",
        "papersel(", $pctyp[1], ",\"pcs[]\");\$\$(\"pctyp_sel\").checked=true;return false'>",
        $pctyp[2], "</a>";
    $sep = ", ";
}
echo ")</td></tr>\n<tr><td></td><td><table class='pctb'><tr><td class='pctbcolleft'><table>";

$pcm = pcMembers();
$nrev = AssignmentSet::count_reviews();
$nrev->pset = AssignmentSet::count_reviews($papersel);
$pcdesc = array();
$colorizer = new Tagger;
foreach ($pcm as $id => $p) {
    $count = count($pcdesc) + 1;
    $color = $colorizer->color_classes($p->contactTags);
    $color = ($color ? " class='${color}'" : "");
    $c = "<tr$color><td class='pctbl'>"
	. Ht::checkbox("pcs[]", $id, isset($pcsel[$id]),
			array("id" => "pcsel$count",
			      "onclick" => "pselClick(event,this);\$\$('pctyp_sel').checked=true"))
	. "&nbsp;</td><td class='pctbname'>"
	. Ht::label(Text::name_html($p), "pcsel$count")
	. "</td></tr><tr$color><td class='pctbl'></td><td class='pctbnrev'>"
	. review_count_report($nrev, $p, "")
	. "</td></tr>";
    $pcdesc[] = $c;
}
$n = intval((count($pcdesc) + 2) / 3);
for ($i = 0; $i < count($pcdesc); $i++) {
    if (($i % $n) == 0 && $i)
	echo "</table></td><td class='pctbcolmid'><table>";
    echo $pcdesc[$i];
}
echo "</table></td></tr></table></td></tr></table>";


// Bad pairs
$numBadPairs = 1;
$badPairSelector = null;

function bpSelector($i, $which) {
    global $numBadPairs, $badPairSelector, $pcm;
    if (!$badPairSelector) {
	$badPairSelector = array("0" => "(PC member)");
	foreach ($pcm as $pc)
	    $badPairSelector[$pc->contactId] = Text::name_html($pc);
    }
    $selected = ($i <= $_REQUEST["bpcount"] ? defval($_REQUEST, "bp$which$i") : "0");
    if ($selected && isset($badPairSelector[$selected]))
	$numBadPairs = max($i, $numBadPairs);
    return Ht::select("bp$which$i", $badPairSelector, $selected,
		       array("onchange" => "if(!((x=\$\$(\"badpairs\")).checked)) x.click()"));
}

echo "<div class='g'></div><div class='relative'><table id='bptable'>\n";
for ($i = 1; $i <= 50; $i++) {
    $selector_text = bpSelector($i, "a") . " &nbsp;and&nbsp; " . bpSelector($i, "b");
    echo "    <tr id='bp$i' class='", ($numBadPairs >= $i ? "auedito" : "aueditc"),
	"'><td class='rentry nowrap'>";
    if ($i == 1)
	echo Ht::checkbox("badpairs", 1, isset($_REQUEST["badpairs"]),
			   array("id" => "badpairs")),
	    "&nbsp;", Ht::label("Don’t assign", "badpairs"), " &nbsp;";
    else
	echo "or &nbsp;";
    echo "</td><td class='lentry'>", $selector_text;
    if ($i == 1)
	echo " &nbsp;to the same paper &nbsp;(<a href='javascript:void authorfold(\"bp\",1,1)'>More</a> | <a href='javascript:void authorfold(\"bp\",1,-1)'>Fewer</a>)";
    echo "</td></tr>\n";
}
echo "</table><input id='bpcount' type='hidden' name='bpcount' value='50' />";
$Conf->echoScript("authorfold(\"bp\",0,$numBadPairs)");
echo "</div>\n";


// Load balancing
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<h3>Load balancing</h3>";
doRadio('balance', 'new', "Spread new assignments equally among PC members");
echo "<br />";
doRadio('balance', 'all', "Spread assignments so that PC members have roughly equal overall load");


// Create assignment
echo "<div class='g'></div>\n";
echo "<div class='aa'><input type='submit' name='assign' value='Prepare assignment' /> &nbsp; <span class='hint'>You’ll be able to check the assignment before it is saved.</span></div>\n";


echo "</div></form>";

$Conf->footer();
