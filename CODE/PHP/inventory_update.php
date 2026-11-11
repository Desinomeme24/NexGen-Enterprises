<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");
require_once __DIR__ . '/inventory_date_helper.php';
require_once __DIR__ . '/inventory_quantity_helper.php';

if (!isset($_SESSION['user_id'])) { header("Location: /NexGen/CODE/PHP/index.php"); exit(); }
if ((int)($_SESSION['can_inventory'] ?? 0)!==1){$_SESSION['inventory_error']='Unauthorized access.';header("Location: /NexGen/CODE/PHP/dashboard.php");exit();}
if ($_SERVER['REQUEST_METHOD']!=='POST'){header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();}
if (!validateCsrfToken('inventory_edit_product', $_POST['csrf_token'] ?? null)) { $_SESSION['inventory_error']='Your session expired. Please try again.'; header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
$businessId=nxRequireBusinessId($conn); $isOwner=($_SESSION['role']??'')==='owner';
$btStmt=$conn->prepare('SELECT b.business_type, be.expiry_date_required FROM businesses b INNER JOIN business_entities be ON be.id=b.business_entity_id WHERE b.id=? LIMIT 1'); $btStmt->bind_param('i',$businessId); $btStmt->execute(); $businessTypeRow=$btStmt->get_result()->fetch_assoc(); $btStmt->close();
$isBatchTracked=nxIsBatchTrackedType($businessTypeRow['business_type']??null);
$requiresExpiryDate=nxRequiresExpiryDate($businessTypeRow['business_type']??null,(int)($businessTypeRow['expiry_date_required']??0)===1);
$id=(int)($_POST['id']??0); $expectedVersion=(int)($_POST['version']??0); $product_code=trim($_POST['product_code']??''); $product_name=trim($_POST['product_name']??''); $category_id=(int)($_POST['category_id']??0); $brand=trim($_POST['brand']??''); $unit=trim($_POST['unit']??''); $cost_price=(float)($_POST['cost_price']??0); $selling_price=(float)($_POST['selling_price']??0); $description=trim($_POST['description']??''); $is_active=(int)($_POST['is_active']??1);
try {
 $stock_quantity=nxValidateWholeQuantity($_POST['stock_quantity']??0,'Stock quantity');
 $reorder_level=$isOwner?nxValidateWholeQuantity($_POST['reorder_level']??null,'Reorder level'):5;
 $on_order_level=$isOwner?nxValidateWholeQuantity($_POST['on_order_level']??null,'On-order quantity'):0;
 if($isBatchTracked){$manufacturing_date=null;$expiry_date=null;}else{$manufacturing_date=nxValidateManufacturingDate($_POST['manufacturing_date']??'');if($manufacturing_date===null)throw new InvalidArgumentException('Manufacturing date is required for every product.');$expiry_date=nxValidateExpiryDate($_POST['expiry_date']??'',$requiresExpiryDate);}
} catch (InvalidArgumentException $exception) {
 $_SESSION['inventory_error']=$exception->getMessage();header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();
}
if(!$isBatchTracked&&$manufacturing_date!==null&&$expiry_date!==null&&$manufacturing_date>$expiry_date){$_SESSION['inventory_error']='Manufacturing date cannot be after the expiry date.';header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();}
if($id<=0||$product_code===''||$product_name===''||$category_id<=0||$unit===''||min($cost_price,$selling_price)<0){$_SESSION['inventory_error']='Invalid product data.';header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();}
$conn->begin_transaction();
try{
 $lock=$conn->prepare('SELECT product_image,version FROM products WHERE id=? AND business_id=? FOR UPDATE');$lock->bind_param('ii',$id,$businessId);$lock->execute();$current=$lock->get_result()->fetch_assoc();$lock->close();if(!$current)throw new Exception('Product not found in your business.');if($expectedVersion<=0||(int)$current['version']!==$expectedVersion)throw new Exception('INVENTORY_CONFLICT');
 $cat=$conn->prepare('SELECT id FROM categories WHERE id=? AND business_id=?');$cat->bind_param('ii',$category_id,$businessId);$cat->execute();if(!$cat->get_result()->fetch_assoc())throw new Exception('Invalid category for this business.');$cat->close();
 $dup=$conn->prepare('SELECT id FROM products WHERE business_id=? AND product_code=? AND id<>? LIMIT 1');$dup->bind_param('isi',$businessId,$product_code,$id);$dup->execute();if($dup->get_result()->fetch_assoc())throw new Exception('Product code already exists in your business.');$dup->close();
 $imagePath=(string)($current['product_image']??'');
 if(!empty($_FILES['product_image']['name'])){$f=$_FILES['product_image'];[$ok,$msg]=nxValidateSecureUpload($f,['allowed_extensions'=>['jpg','jpeg','png','webp'],'allowed_mime_types'=>['image/jpeg','image/png','image/webp'],'max_size'=>5*1024*1024,'require_image'=>true,'allow_pdf'=>false]);if(!$ok)throw new Exception('Product image blocked: '.$msg);$dir = rtrim(nxPublicUploadDirectory(), "\\/") . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR;if(!is_dir($dir))mkdir($dir,0777,true);$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));$name='product_'.bin2hex(random_bytes(8)).'.'.$ext;if(!move_uploaded_file($f['tmp_name'],$dir.$name))throw new Exception('Failed to upload product image.');$imagePath='uploads/products/'.$name;}
 // Batch-tracked businesses never let this form write stock_quantity/expiry_date
 // directly - those come from product_batches (via the triggers) and Adjust Stock.
 if($isBatchTracked){
  if($isOwner){$sql='UPDATE products SET product_code=?,product_name=?,category_id=?,brand=?,unit=?,cost_price=?,selling_price=?,reorder_level=?,on_order_level=?,product_image=?,description=?,is_active=?,version=version+1 WHERE id=? AND business_id=? AND version=?';$st=$conn->prepare($sql);$st->bind_param('ssissddddssiiii',$product_code,$product_name,$category_id,$brand,$unit,$cost_price,$selling_price,$reorder_level,$on_order_level,$imagePath,$description,$is_active,$id,$businessId,$expectedVersion);}else{$sql='UPDATE products SET product_code=?,product_name=?,category_id=?,brand=?,unit=?,cost_price=?,selling_price=?,product_image=?,description=?,is_active=?,version=version+1 WHERE id=? AND business_id=? AND version=?';$st=$conn->prepare($sql);$st->bind_param('ssissddssiiii',$product_code,$product_name,$category_id,$brand,$unit,$cost_price,$selling_price,$imagePath,$description,$is_active,$id,$businessId,$expectedVersion);}
 }elseif($isOwner){$sql='UPDATE products SET product_code=?,product_name=?,category_id=?,brand=?,unit=?,cost_price=?,selling_price=?,stock_quantity=?,reorder_level=?,on_order_level=?,manufacturing_date=?,expiry_date=?,product_image=?,description=?,is_active=?,version=version+1 WHERE id=? AND business_id=? AND version=?';$st=$conn->prepare($sql);$st->bind_param('ssissdddddssssiiii',$product_code,$product_name,$category_id,$brand,$unit,$cost_price,$selling_price,$stock_quantity,$reorder_level,$on_order_level,$manufacturing_date,$expiry_date,$imagePath,$description,$is_active,$id,$businessId,$expectedVersion);}else{$sql='UPDATE products SET product_code=?,product_name=?,category_id=?,brand=?,unit=?,cost_price=?,selling_price=?,stock_quantity=?,manufacturing_date=?,expiry_date=?,product_image=?,description=?,is_active=?,version=version+1 WHERE id=? AND business_id=? AND version=?';$st=$conn->prepare($sql);$st->bind_param('ssissdddssssiiii',$product_code,$product_name,$category_id,$brand,$unit,$cost_price,$selling_price,$stock_quantity,$manufacturing_date,$expiry_date,$imagePath,$description,$is_active,$id,$businessId,$expectedVersion);}
 if(!$st->execute()||$st->affected_rows!==1)throw new Exception('INVENTORY_CONFLICT');$st->close();$conn->commit();$_SESSION['inventory_success']='Product updated successfully.';
}catch(Throwable $e){$conn->rollback();if(isset($current['product_image'],$imagePath)&&$imagePath!==$current['product_image']&&str_starts_with($imagePath,'uploads/products/')){@unlink(rtrim(nxPublicUploadDirectory(),"\\/").DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.basename($imagePath));}if($e->getMessage()==='INVENTORY_CONFLICT'){$_SESSION['inventory_conflict']=true;}else{$_SESSION['inventory_error']=$e->getMessage();}}
header("Location: /NexGen/CODE/PHP/inventory_management.php");exit();
?>