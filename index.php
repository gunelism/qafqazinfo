<?php 

class Qafqazinfo
{
	function __construct($servername="localhost",$username='root',$password='',$dbname)
	{
		$this->curl=curl_init();
		$this->servername = $servername;
		$this->username = $username;
		$this->password = $password;
		$this->dbname = $dbname;
		$this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);
		if ($this->conn->connect_error) {
		    die("Connection failed: " . $this->conn->connect_error);
		}

		$this->cat_id='';
		$this->news_arr=[];
		$this->date_difference='';
	}

	// categories start
	public function getCategories(){
		$result = $this->curl('http://qafqazinfo.az');

		preg_match_all("!http://qafqazinfo.az/news/category/([a-zA-Z]+)-(?P<digit>\d+)!", $result, $matches);
		$categories = array_values(array_unique($matches[0]));
		$this->parseCategories($categories);
	}
	private function parseCategories($categories){
		for ($i=0; $i < count($categories); $i++) { 
			$slash_exp = explode('/',$categories[$i]);
			$tire_exp = explode('-',end($slash_exp));
			$this->saveCategory($tire_exp);
		}
	}
	private function saveCategory($cat_name_id){
		$cat_name = $cat_name_id[0];
		$cat_id = end($cat_name_id);
		$rows = mysqli_query($this->conn,"SELECT `id` FROM `categories` WHERE `cat_id`='".$cat_id."'");
		if(mysqli_num_rows($rows)==0){
			$insert = "INSERT INTO `categories`(`id`, `cat_id`, `name`) VALUES ('','".$cat_id."','".$cat_name."')";
			mysqli_query($this->conn,$insert);
		}
	} 
	// categories end

	// news start
	public function getNewsByCat($days){
		$cats = mysqli_query($this->conn,"SELECT * FROM `categories`");
		while ($cat = mysqli_fetch_assoc($cats)){
			$this->cat_id=$cat['cat_id'];
			$page=1;
			$this->getNews($cat['name'],$cat['cat_id'],$page,$days);
			
			while($this->date_difference>0){
				$page++;
				$this->getNews($cat['name'],$cat['cat_id'],$page,$days);
			}
			$this->saveNews($this->news_arr);	
		}
	}
	private function getNews($cat_name,$cat_id,$page,$days){
		if($page>1) $this->curl('http://qafqazinfo.az/news/category/'.$cat_name.'-'.$cat_id.'?page='.$page);
		else $this->curl('http://qafqazinfo.az/news/category/'.$cat_name.'-'.$cat_id);
		$result = curl_exec($this->curl);
		preg_match_all("/\<div class=\"row search\"\>(.*?)\<\/div\>/is",$result, $matches );	
		preg_match_all("/\<div class=\"col-lg-9 col-md-9 col-sm-9 col-xs-7\"\>.*?\<\/div\>/is",$result, $matches_date );	

		$news = array_values(array_unique($matches[0]));
		$this->parseNews($news,$matches_date,$days);
	}
	private function parseNews($news,$news_date,$days){
		
		for ($i=0; $i < count($news); $i++) { 
			preg_match("/\<div\>.*?\<\/div\>/is",$news_date[0][$i], $date );
			$trim_date = preg_replace('/\s+/', ' ', $date[0]);
			$trim_date1 = substr($trim_date, 6);
			$trim_date2 = substr($trim_date1, 0, -6);
			$date=date_create($trim_date2);
			$days_ago = date('Y-m-d', strtotime('-'.$days.' days', strtotime(date('Y/m/d'))));

			$date_diff = $this->dateDifference($days_ago,$trim_date2);
			$date_diff=(int)$date_diff;
			if($date_diff>-1){
				preg_match_all('/<a target="_blank" href="(.*)" title="(.*)">/',$news[$i], $urlmatches);
				preg_match_all('/<img src="(.*)" class="(.*)">/',$news[$i], $imgmatches);
				// $news_link=$urlmatches[1];
				$get_id = explode('-', $urlmatches[1][0]);

				$data['news_id']=end($get_id);
				$data['news_title']=$urlmatches[2][0];
				$data['news_link']=$urlmatches[1][0];
				$data['news_image']=$imgmatches[1][0];
				$data['news_date']=$trim_date2;
				array_push($this->news_arr, $data);
				$this->date_difference=$date_diff;
			}
		}
	}
	private function dateDifference($date_1 , $date_2 , $differenceFormat = '%a' )
	{
	    $datetime1 = date_create($date_1);
	    $datetime2 = date_create($date_2);
	    
	    $interval = date_diff($datetime1, $datetime2);
	    
	    return $interval->format("%R%a");
	}
	private function saveNews($data){
		for ($i=0; $i < count($data); $i++) { 
			$rows = mysqli_query($this->conn,"SELECT `id` FROM `news` WHERE `news_id`='".$data[$i]['news_id']."'");
			if(mysqli_num_rows($rows)==0){
				$insert = "INSERT INTO `news`(`id`, `news_id`, `category_id`, `title`, `body`, `image`, `link`, `video_link`, `date`) VALUES ('','".$data[$i]['news_id']."','".$this->cat_id."','".$data[$i]['news_title']."','','".$data[$i]['news_image']."','".$data[$i]['news_link']."','','".$data[$i]['news_date']."')";
				mysqli_query($this->conn,$insert);
			}
		}
		$this->news_arr=[];			
	}
	// news end

	// news body
	public function getNewsBody(){
		$rows = mysqli_query($this->conn,"SELECT `id` FROM `news`");
		$news_q = mysqli_query($this->conn,"SELECT `link`,`news_id` FROM `news` where `body`=''");
		while (($row = mysqli_fetch_assoc($news_q))){
			$result = $this->curl($row['link']);
			preg_match_all("/<p>([^`]*?)<\/p>/", $result, $matches);
			$body = implode(' ', $matches[0]);
			$content1 = preg_replace('/(<)([img])(\w+)([^>]*>)/', '', $body);
			$content = str_replace("'", "", $content1);
			// $this->getVideo($result);
			$query = mysqli_query($this->conn,"UPDATE `news` SET `body`='".$content."' WHERE `news_id`='".$row['news_id']."'");
		}
	}
	// news body end
	

	// word count 
	public function getWordSentenceCount(){
		$news_q = mysqli_query($this->conn,"SELECT `body`,`news_id` FROM `news` WHERE `body`!='' ");
		while (($row = mysqli_fetch_assoc($news_q))){
			$words = str_word_count($row['body']);
			$content1 = str_replace('...', '.', $row['body']);
			$content = str_replace('.s', ' ', $content1);
			$sentences = substr_count($content,".");
			$query = mysqli_query($this->conn,"INSERT INTO `statistics`(`id`, `news_id`, `word_count`, `sentence_count`, `most_user_words`) VALUES ('','".$row['news_id']."','".$words."','".$sentences."','')");

		}
	}
	public function getVideo(){
		$this->curl('http://qafqazinfo.az/news/detail/arvadini-namaz-qildigi-yerde-21-defe-bicaqladi-video-222402');
		$result = curl_exec($this->curl);
		// print_r($result);
		preg_match_all('/<iframe.*src=\"(.*)\".*><\/iframe>/isU', $result, $cont);
		// for ($i=0; $i < count($cont[1]); $i++) { 
		// 	if (strpos($cont[1][$i], 'youtube') !== false || strpos($cont[1][$i], 'video') !== false) {
		// 	    echo $cont[1][$i];
		// 	}
		// }
		
	}
	// word count end 	

	private function curl($url){
		curl_setopt($this->curl, CURLOPT_URL, $url);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
		return curl_exec($this->curl);
	}
	
	
}

$data = new Qafqazinfo('localhost','root','',"qafqazinfo");
// $data->getCategories();
// $data->getNewsByCat();
// $data->getNewsBody();
// $data->getWordSentenceCount();
if($_POST){
	if(isset($_POST['get_categories']) && $_POST['get_categories']==1){
		$data->getCategories();
		$_SESSION['cats']='Categories are imported.';
	}
	elseif(isset($_POST['get_news']) && $_POST['get_news']==1){
		if (isset($_POST['days'])) {
			$days=intval($_POST['days']);
		}else{
			$days='3';
		}
		$data->getNewsByCat($days);
		$_SESSION['get_news']='News imported.';
	}
	elseif(isset($_POST['fill_news']) && $_POST['fill_news']==1){
		$data->getNewsBody();
		$_SESSION['fill_news']='If time exceedes press again';
	}
	elseif(isset($_POST['statistics']) && $_POST['statistics']==1){
		$data->getWordSentenceCount();
		$_SESSION['statistics']='Statistics are calculated.';
	}
}
?>

<!DOCTYPE html>
<html>
<head>
	<title></title>
</head>
<body>
	<form action="" method="post">
		<input type="hidden" name="get_categories" value="1">
		1. <button type="submit">Get Categories</button>
		<?php if(isset($_SESSION['cats'])){
				echo  $_SESSION['cats'];
				unset($_SESSION['cats']);
				}
		 ?>
	</form>
	<form action="" method="post">
		<input type="hidden" name="get_news" value="1">
		2. How many days before:
		<input type="number" name="days">
		<button type="submit">Get News</button>
		<?php if(isset($_SESSION['get_news'])){
				echo  $_SESSION['get_news'];
				unset($_SESSION['get_news']);
				}
		 ?>
	</form>
	<form action="" method="post">
		<input type="hidden" name="fill_news" value="1">
		3. <button type="submit">Fill News</button>
		<?php if(isset($_SESSION['fill_news'])){
				echo  $_SESSION['fill_news'];
				unset($_SESSION['fill_news']);
				}
		 ?>
	</form>
	<form action="" method="post">
		<input type="hidden" name="statistics" value="1">
		4. <button type="submit">Calculate statistics</button>
		<?php if(isset($_SESSION['statistics'])){
				echo  $_SESSION['statistics'];
				unset($_SESSION['statistics']);
				}
		 ?>
	</form>
</body>
</html>
