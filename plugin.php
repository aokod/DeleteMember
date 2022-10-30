<?php
if (!defined("IN_ESO")) exit;

class DeleteMember extends Plugin {

var $id = "DeleteMember";
var $name = "DeleteMember";
var $version = "1.0";
var $description = "Delete members and their posts";
var $author = "grntbg";

function init()
{
	$this->eso->addLanguage("confirmDeleteUser", "Are you sure you want to delete this member? Seriously, you won't be able to get them back.");
	$this->eso->addLanguage("Delete user", "Delete member");
	$this->eso->addMessage("userDeleted", "success", "This member and their posts were deleted. ;^(");
	$this->eso->addMessage("noPostsAffected", "warning", "There was a problem deleting this member's posts.");
//	$this->eso->addMessage("noConversationsAffected", "warning", "There was a problem deleting this member's conversations.");
	$this->eso->addMessage("noUserAffected", "warning", "There was a problem deleting this member, have they already been deleted?");

	if ($this->eso->action == "profile") {
		$this->eso->addScript("plugins/DeleteMember/deleteMember.js");
		$this->eso->addLanguageToJS("confirmDeleteUser");

		$this->eso->controller->addHook("init", array($this, "addDeleteSettings"));
	}
	$this->eso->controller->addHook("init", array($this, "isUserValid"));
}// init

function canDeleteUser($memberId)
{
	global $config;

	// Is the end user a moderator?
	if ($memberId == $config["rootAdmin"]) return false;
	$account = $this->eso->db->result("SELECT account FROM {$config["tablePrefix"]}members WHERE memberId=$memberId", 0);
	if ($account == "Moderator" || $account == "Administrator") return false;

	return true;
}// canDeleteUser

function addDeleteSettings(&$controller)
{
	global $language;
	$this->member =& $controller->member;

	$memberId = $this->member["memberId"];
	if ($this->eso->user["moderator"] and $this->canDeleteUser($memberId) == true) $this->eso->addToBar("right", "<a href='" . makeLink("profile",$memberId, "?deleteUser", "&token={$_SESSION["token"]}") . "' onclick='return DeleteUser.deleteUser()'><span class='button buttonSmall'><input type='submit' value='{$language["Delete user"]}'></span></a>");

	// Delete user: delete the user and then redirect to the index.
	if (isset($_GET["deleteUser"]) and $this->eso->validateToken(@$_GET["token"]) and $this->doDeleteUser($memberId)) {
		$this->eso->message("userDeleted");
		redirect("");
	}
}// addDeleteSettings

function isUserValid()
{
	global $config;
	// If the user's memberId doesn't exist in the database, log them out!
	if ($this->eso->user and !$this->eso->db->result("SELECT memberId FROM {$config["tablePrefix"]}members WHERE memberId={$this->eso->user["memberId"]}")) $this->eso->logout();
}// isUserValid

function doDeleteUser($memberId)
{
	// Does the user have permission?
	if (!$this->eso->user["admin"] || !$this->canDeleteUser($memberId)) {
		$this->eso->message("noPermission");
		return false;
	} elseif (!$this->deletePosts($memberId)) {
		$this->eso->message("noPostsAffected");
		return false;
	} elseif (!$this->deleteUser($memberId)) {
		$this->eso->message("noUserAffected");
		return false;
	} else {
		return true;
	}
}// doDeleteUser

function deletePosts($memberId)
{	
	global $config;

    // Construct the select component of the query.
	$result = $this->eso->db->query("SELECT c.conversationId, c.startMember, p.deleteMember FROM {$config["tablePrefix"]}conversations c, {$config["tablePrefix"]}posts p WHERE p.memberId=$memberId AND c.conversationId=p.conversationId ORDER BY time", 0);
    $conversations = array();
    $i = 0;

	while ($conversation = $this->eso->db->fetchAssoc($result)) {
		$k = isset($conversation["number"]) ? $conversation["number"] : $i;

		// Build the post array.
		$conversations[$k] = array(
			"conversationId" => $conversation["conversationId"],
			"startMember" => $conversation["startMember"]
		) ? : array("deleteMember" => $conversation["deleteMember"]);

        // Fix the lastPostMember and lastPostTime of the conversation.
        $this->fixLastPost($memberId, $conversation["conversationId"]);

		// Fix the post count of the conversation.
		$this->fixCountPost($memberId, $conversation["conversationId"]);

		if ($conversation["startMember"] == $memberId) $this->deleteConversation($conversation["conversationId"]);

		$i++;
	}

//	// If the query didn't affect any rows, either we didn't have permission or there aren't any posts...
//	if (!$this->eso->db->affectedRows()) {
//		return false;
//	}

	return true;
}// deletePosts

function fixLastPost($memberId, $conversationId)
{
	global $config;

	// Does the last post of this conversation belong to that member?
	$lastPost = $this->eso->db->result("SELECT memberId FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId ORDER BY time DESC LIMIT 1");
	// How many posts are there in the conversation?
	$posts = $this->eso->db->result("SELECT COUNT(*) FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId AND memberId!=$memberId AND deleteMember IS NULL ORDER BY time");
	// Prevent necrobumping (change the lastPostMember/Time to that of the previous post.)
	if ($posts > 0 | $lastPost == $memberId) {
		$lastPostMember = $this->eso->db->result("SELECT memberId FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId AND memberId!=$memberId AND deleteMember IS NULL ORDER BY time DESC LIMIT 1");
		// If there is no previous member, all the posts in the conversation have been deleted. :(
		if (empty($lastPostMember)) return false;
		$lastPostTime = $this->eso->db->result("SELECT time FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId AND memberId!=$memberId AND deleteMember IS NULL ORDER BY time DESC LIMIT 1");
		$query = "UPDATE {$config["tablePrefix"]}conversations
			SET lastPostMember=$lastPostMember, lastPostTime=$lastPostTime
			WHERE conversationId=$conversationId";
		$this->eso->db->query($query);
	// If all the posts in the conversation are deleted, get the first post and use that.
	} elseif ($posts = 0) {
		$lastPostMember = $this->eso->db->result("SELECT startMember FROM {$config["tablePrefix"]}conversations WHERE conversationId=$conversationId");
		$lastPostTime = $this->eso->db->result("SELECT startTime FROM {$config["tablePrefix"]}conversations WHERE conversationId=$conversationId");
//		$firstMemberId = $this->eso->db->result("SELECT memberId FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId AND memberId!=$memberId ORDER BY time ASC LIMIT 1");
		// If there is no first member ID, this conversation was created by the member we're deleting (it's going to be deleted anyway).
//		if (empty($firstMemberId)) return false;
//		$time = $this->eso->db->result("SELECT time FROM {$config["tablePrefix"]}posts WHERE conversationId=$conversationId AND memberId!=$memberId ORDER BY time ASC LIMIT 1");
		$query = "UPDATE {$config["tablePrefix"]}conversations
			SET lastPostMember=$lastPostMember, lastPostTime=$lastPostTime
			WHERE conversationId=$conversationId";
		$this->eso->db->query($query);
	} else {
		return false;
	}

	return true;
}// fixLastPost

function fixCountPost($memberId, $conversationId)
{
	global $config;

	$posts = $this->eso->db->result("SELECT COUNT(*) FROM {$config["tablePrefix"]}posts
		WHERE conversationId=$conversationId AND memberId!=$memberId ORDER BY time");
	if ($posts > 0) {
		$query = "UPDATE {$config["tablePrefix"]}conversations SET posts=$posts WHERE conversationId=$conversationId";
		$this->eso->db->query($query);
	}

	return true;
}

function deleteConversation($conversationId)
{
	global $config;

	// Delete the conversation, statuses, posts, and tags from the database.
	$query = "DELETE c, s, p, t FROM {$config["tablePrefix"]}conversations c
		LEFT JOIN {$config["tablePrefix"]}status s ON (s.memberId=c.startMember)
		LEFT JOIN {$config["tablePrefix"]}posts p ON (p.memberId=c.startMember)
		LEFT JOIN {$config["tablePrefix"]}tags t ON (t.conversationId=c.conversationId)
		WHERE c.conversationId=$conversationId";
	$this->eso->db->query($query);

	return true;
}

function deleteUser($memberId)
{
	global $config;

	// Delete the member, searches, logins, and remaining posts from the database.
	$query = "DELETE m, s, l, p FROM {$config["tablePrefix"]}members m
		LEFT JOIN {$config["tablePrefix"]}searches s ON (s.ip=m.cookieIP)
		LEFT JOIN {$config["tablePrefix"]}logins l ON (l.ip=m.cookieIP)
		LEFT JOIN {$config["tablePrefix"]}posts p ON (p.memberId=m.memberId)
		WHERE m.memberId=$memberId";
	$this->eso->db->query($query);

	// If the query didn't affect any rows, something is wrong!
	if (!$this->eso->db->affectedRows()) {
		$this->eso->message("noUserAffected");
		return false;
	}

	return true;
}

}

?>
