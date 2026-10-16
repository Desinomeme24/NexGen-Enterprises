<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

if (!isset($_SESSION['user_id'])) { header("Location: /NexGen/CODE/PHP/index.php"); exit(); }
if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) { $_SESSION['inventory_error']='Unauthorized access.'; header("Location: /NexGen/CODE/PHP/dashboard.php"); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
if (!validateCsrfToken('inventory_add_product', $_POST['csrf_token'] ?? null)) { $_SESSION['inventory_error']='Your session expired. Please try again.'; header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
$businessId=nxRequireBusinessId($conn); $role=$_SESSION['role']??'employee'; $isOwner=$role==='owner';
$btStmt=$conn->prepare('SELECT business_type FROM businesses WHERE id=? LIMIT 1'); $btStmt->bind_param('i',$businessId); $btStmt->execute(); $businessTypeRow=$btStmt->get_result()->fetch_assoc(); $btStmt->close();
$isBatchTracked=nxIsBatchTrackedType($businessTypeRow['business_type']??null);
$product_code=trim($_POST['product_code']??''); $product_name=trim($_POST['product_name']??''); $category_id=(int)($_POST['category_id']??0);
$brand=trim($_POST['brand']??''); $unit=trim($_POST['unit']??''); $cost_price=(float)($_POST['cost_price']??0); $selling_price=(float)($_POST['selling_price']??0);
$discount_percent=max(0,min(100,(float)($_POST['discount_percent']??0)));
$stock_quantity=(float)($_POST['stock_quantity']??0); $reorder_level=$isOwner?(float)($_POST['reorder_level']??5):5; $on_order_level=$isOwner?(float)($_POST['on_order_level']??0):0;
$expiry_date=!empty($_POST['expiry_date'])?$_POST['expiry_date']:null; $description=trim($_POST['description']??''); $is_active=(int)($_POST['is_active']??1);
$batch_number=$isBatchTracked?nxGenerateBatchNumber():'';
if ($product_code===''||$product_name===''||$category_id<=0||$unit==='') { $_SESSION['inventory_error']='Please fill in all required fields.'; header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
if ($isBatchTracked && $stock_quantity<=0) { $_SESSION['inventory_error']='A positive initial quantity is required for this business type. Expiry date is optional.'; header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
if (min($cost_price,$selling_price,$stock_quantity,$reorder_level,$on_order_level)<0) { $_SESSION['inventory_error']='Numeric values must not be negative.'; header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
$cat=$conn->prepare('SELECT id FROM categories WHERE id=? AND business_id=?'); $cat->bind_param('ii',$category_id,$businessId); $cat->execute(); $catOk=$cat->get_result()->fetch_assoc(); $cat->close();
if(!$catOk){$_SESSION['inventory_error']='Invalid category for this business.';header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();}
$imagePath='';
if(!empty($_FILES['product_image']['name'])){ $f=$_FILES['product_image']; [$ok,$msg]=nxValidateSecureUpload($f,['allowed_extensions'=>['jpg','jpeg','png','webp'],'allowed_mime_types'=>['image/jpeg','image/png','image/webp'],'max_size'=>5*1024*1024,'require_image'=>true,'allow_pdf'=>false]); if(!$ok){$_SESSION['inventory_error']='Product image blocked: '.$msg;header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();} $dir = rtrim(nxPublicUploadDirectory(), "\\/") . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR; if(!is_dir($dir))mkdir($dir,0777,true); $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); $name='product_'.bin2hex(random_bytes(8)).'.'.$ext; if(!move_uploaded_file($f['tmp_name'],$dir.$name)){$_SESSION['inventory_error']='Failed to upload product image.';header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();} $imagePath='uploads/products/'.$name; }
$conn->begin_transaction();
try{
 $check=$conn->prepare('SELECT id FROM products WHERE business_id=? AND product_code=? LIMIT 1'); $check->bind_param('is',$businessId,$product_code); $check->execute(); if($check->get_result()->fetch_assoc())throw new Exception('Product code already exists in your business.'); $check->close();
 // Batch-tracked businesses start the product at 0 stock and no product-level
 // expiry - the initial batch inserted below sets the real stock via the
 // product_batches triggers, and each batch carries its own expiry date.
 $initialStock=$isBatchTracked?0:$stock_quantity; $initialExpiry=$isBatchTracked?null:$expiry_date;
 $stmt=$conn->prepare('INSERT INTO products (business_id,product_code,product_name,category_id,brand,unit,cost_price,selling_price,discount_percent,stock_quantity,reorder_level,on_order_level,expiry_date,product_image,description,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
 $stmt->bind_param('ississddddddsssi',$businessId,$product_code,$product_name,$category_id,$brand,$unit,$cost_price,$selling_price,$discount_percent,$initialStock,$reorder_level,$on_order_level,$initialExpiry,$imagePath,$description,$is_active);
 if(!$stmt->execute())throw new Exception('Failed to save product: '.$stmt->error); $newProductId=$conn->insert_id; $stmt->close();
 if($isBatchTracked){
  $batchStmt=$conn->prepare('INSERT INTO product_batches (business_id,product_id,batch_number,expiry_date,quantity) VALUES (?,?,?,?,?)');
  $batchStmt->bind_param('iissd',$businessId,$newProductId,$batch_number,$expiry_date,$stock_quantity);
  if(!$batchStmt->execute())throw new Exception('Failed to save initial batch: '.$batchStmt->error); $batchStmt->close();
 }
 $conn->commit(); $_SESSION['inventory_success']='Product added successfully.';
}catch(Throwable $e){$conn->rollback(); if($imagePath!==''&&file_exists(__DIR__.'/'.$imagePath))@unlink(__DIR__.'/'.$imagePath); $_SESSION['inventory_error']=$e->getMessage();}
header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit();
?>