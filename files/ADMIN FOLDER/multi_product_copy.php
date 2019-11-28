<?php
/**
 * @package admin
 * @copyright Copyright 2003-2010 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0

   $Id: multi_product_copy.php ver 1.11 by Kevin L. Shelton 2010-10-15
   $Id: multi_product_copy.php ver 1.391 by Linda McGrath 2011-11-10
*/
/**
 * Main program
*/

  require('includes/application_top.php');
  require(DIR_WS_CLASSES . 'currencies.php');

  $currencies = new currencies();

  function list_subcategories($parent_id) {
    global $db;

    $list_categories = $db->Execute("select categories_id from " . TABLE_CATEGORIES . " where parent_id = " . (int)$parent_id);
    $list = '';
    while (!$list_categories->EOF) {
      $list .= ',' . $list_categories->fields['categories_id'] . list_subcategories($list_categories->fields['categories_id']);
      $list_categories->MoveNext();
    }
    return $list;
  }

  $action = (isset($_GET['action']) ? $_GET['action'] : 'new');
  $messages = array();
  $error = false;
  if (($action == 'find') || ($action == 'confirm')) { //validate form
    $autocheck = (isset($_POST['autocheck']) && ($_POST['autocheck'] == 'yes'));
    $keywords = (isset($_POST['keywords']) ? zen_db_prepare_input($_POST['keywords']) : '');
    $within = (isset($_POST['within']) ? $_POST['within'] : 'all');
    if (zen_not_null($keywords)) {
      if (!zen_parse_search_string($keywords, $search_keywords)) {
        $error = true;
        $messages[] = ERROR_INVALID_KEYWORDS;
      }
    }
    $category_id = (isset($_POST['category_id']) ? $_POST['category_id'] : '');
    if (!is_numeric($category_id)) $category_id = '';
    $inc_subcats = (isset($_POST['inc_subcats']) && ($_POST['inc_subcats'] == 'yes'));
    $copy_to = zen_db_prepare_input($_POST['copy_to']);

    $copy_as = $_POST['copy_as'];
    if (!is_numeric($copy_to)) $copy_to = '';
    if ($copy_to == '' && $_POST['copy_as'] != 'deleted') {
      $error = true;
      $messages[] = ERROR_NO_LOCATION;
    } elseif ($copy_to == $category_id && $_POST['copy_as'] != 'deleted') {
      $error = true;
      $messages[] = ERROR_SAME_LOCATION;
    } else {
      if ($_POST['copy_as'] != 'deleted') {
        $check = $db->Execute('select * from ' . TABLE_CATEGORIES . ' where categories_id = ' . (int)$copy_to);
        if ($check->RecordCount() != 1) {
          $error = true;
          $messages[] = ERROR_NOT_FOUND;
        } else {
          $check = 'select categories_name from ' . TABLE_CATEGORIES_DESCRIPTION . ' where categories_id = ' . (int)$copy_to . ' and language_id = ' . (int)$_SESSION['languages_id'];
          $cat = $db->Execute($check);
          $copy_to_name = $cat->fields['categories_name'];
          if (!zen_not_null($copy_to_name)) {
            $error = true;
            $messages[] = ERROR_NAME_NOT_FOUND;
          }
        }
      } // deleted
    }

    $check = $db->Execute("select products_id from " . TABLE_PRODUCTS_TO_CATEGORIES . " where categories_id = " . (int)$copy_to);
    $products_in_copyto = array();
    while (!$check->EOF) { // build list of products in destination category
      $products_in_copyto[] = $check->fields['products_id'];
      $check->MoveNext();
    }
    $manufacturer_id = (isset($_POST['manufacturer_id']) ? $_POST['manufacturer_id'] : '');
    if (!is_numeric($manufacturer_id)) $manufacturer_id = '';
    $min_price = zen_db_prepare_input($_POST['min_price']);
    if (!is_numeric($min_price)) $min_price = '';
    $max_price = zen_db_prepare_input($_POST['max_price']);
    if (!is_numeric($max_price)) $max_price = '';
    $product_quantity = zen_db_prepare_input($_POST['product_quantity']);
    if (!is_numeric($product_quantity)) $product_quantity = '';

    if (($keywords == '') && ($category_id == '') && ($manufacturer_id == '') && ($min_price == '') && ($max_price == '') && ($min_msrp == '') && ($max_msrp == '') && ($product_quantity == '')) {
      $error = true;
      $messages[] = ERROR_ENTRY_REQUIRED;
    }
    if ($action == 'confirm') { // perform additional validations
      $cnt = (int)$_POST['product_count'];
      $found = explode(',', $_POST['items_found']);
      if ($cnt != sizeof($found)) {
        $messages[] = ERROR_UNEXPLAIN;
        $action = 'find';
      }
      $set_items = $_POST['product'];
      if (sizeof($set_items) == 0) {
        $messages[] = ERROR_NOT_SELECTED;
        $action = 'find';
      } elseif (!is_array($set_items)) {
        $messages[] = ERROR_UNEXPLAIN;
        $action = 'find';
      } else {
        foreach($set_items as $item)
          if (!in_array($item, $found)) {
            $messages[] = ERROR_UNEXPLAIN;
            $action = 'find';
            break;
          }
      }
    }
    if ($error) {  // if error return to entry form
      $action = 'new';
    }
  }
  if (zen_not_null($action)) {
    switch ($action) {
      case 'confirm':
        $items_set = array();
        foreach($set_items as $id) {
          if (!in_array($id, $products_in_copyto)) { // product not already in destination
            $query = $db->Execute("select * from " . TABLE_PRODUCTS . " p left join " . TABLE_MANUFACTURERS . " m on p.manufacturers_id = m.manufacturers_id, " . TABLE_PRODUCTS_DESCRIPTION . " pd where p.products_id = pd.products_id and pd.language_id =  " . (int)$_SESSION['languages_id'] . ' and p.products_id = ' . (int)$id);

// bof: move from one category to another
            if ($_POST['copy_as'] == 'move_from' && $query->RecordCount() == 1) { //if product found
              $product_multi = $query;
/*
echo 'Copy From: cat: ' . $category_id . ' vs post: ' . $_POST['category_id'] . '<br>';
echo 'Copy To: ' . $copy_to . '<br>';
echo 'IF THIS IS YES ' . (zen_childs_in_category_count($_POST['category_id']) > 0 ? 'YES' : 'NO') . '<br>';
echo '<br><br>';

echo '<pre>'; echo var_dump($_POST); echo '</pre>';
die('DONE');
*/
              $action = 'multiple_product_copy_return';
              $_POST['products_id'] = $id;
              $_POST['move_to_category_id'] = $copy_to;
              if (zen_childs_in_category_count($_POST['category_id']) > 0 || $_POST['category_id'] == 0) {
                $current_category_id = $product_multi['master_categories_id'];
              } else {
                $current_category_id = $category_id;
              }

              $product_type = zen_get_products_type($id);
              if (file_exists(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/move_product_confirm.php')) {
                require(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/move_product_confirm.php');
              } else {
                require(DIR_WS_MODULES . 'move_product_confirm.php');
              }
            } // eof link
// bof: move from one category to another

            if ($_POST['copy_as'] == 'link' && $query->RecordCount() == 1) { //if product found
              $product_multi = $query;
              $items_set[] = array('id' => $product_multi->fields['products_id'],
                                   'manufacturer' => $product_multi->fields['manufacturers_name'],
                                   'model' => $product_multi->fields['products_model'],
                                   'name' => $product_multi->fields['products_name'],
                                   'price' => zen_get_products_display_price($product_multi->fields['products_id']));
              $data_array = array('products_id' => $id,
                                  'categories_id' => $copy_to);
              zen_db_perform(TABLE_PRODUCTS_TO_CATEGORIES, $data_array);
            } // eof link
            if ($_POST['copy_as'] == 'duplicate' && $query->RecordCount() == 1) { //if product found
              $action = 'multiple_product_copy_return';
              $_POST['products_id'] = $id;
              $_POST['categories_id'] = $copy_to;
              $product_type = zen_get_products_type($id);
              if (file_exists(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/copy_to_confirm.php')) {
                require(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/copy_to_confirm.php');
              } else {
                require(DIR_WS_MODULES . 'copy_to_confirm.php');
              }

              if ($_POST['copy_specials'] == 'copy_specials_yes') {
                $chk_specials = $db->Execute("select * from " . TABLE_SPECIALS . " WHERE products_id= " . $id);
                while (!$chk_specials->EOF) {
                  $db->Execute("insert into " . TABLE_SPECIALS . "
                              (products_id, specials_new_products_price, specials_date_added, expires_date, status, specials_date_available)
                              values ('" . (int)$dup_products_id . "',
                                      '" . zen_db_input($chk_specials->fields['specials_new_products_price']) . "',
                                      now(),
                                      '" . zen_db_input($chk_specials->fields['expires_date']) . "', '1', '" . zen_db_input($chk_specials->fields['specials_date_available']) . "')");
                  $chk_specials->MoveNext();
                }
              }

              if ($_POST['copy_featured'] == 'copy_featured_yes') {
                $chk_featured = $db->Execute("select * from " . TABLE_FEATURED . " WHERE products_id= " . $id);
                while (!$chk_featured->EOF) {
                  $db->Execute("insert into " . TABLE_FEATURED . "
                              (products_id, featured_date_added, expires_date, status, featured_date_available)
                              values ('" . (int)$dup_products_id . "',
                                      now(),
                                      '" . zen_db_input($chk_featured->fields['expires_date']) . "', '1', '" . zen_db_input($chk_featured->fields['featured_date_available']) . "')");

                  $chk_featured->MoveNext();
                }
              }

              // reset products_price_sorter for searches etc.
              zen_update_products_price_sorter((int)$id);

              $product_multi = $query;
              $items_set[] = array('id' => $product_multi->fields['products_id'],
                                   'manufacturer' => $product_multi->fields['manufacturers_name'],
                                   'model' => $product_multi->fields['products_model'],
                                   'name' => $product_multi->fields['products_name'],
                                   'price' => zen_get_products_display_price($product_multi->fields['products_id']));

            } // eof duplicate
            if ($_POST['copy_as'] == 'deleted' && $query->RecordCount() == 1) { //if product found
              $action = 'multiple_product_copy_return';
              $_POST['products_id'] = $id;

              $delete_linked = 'true';
              $product_type = zen_get_products_type($id);

              $product_categories = array();
              $chk_categories = $db->Execute("select products_id, categories_id from " . TABLE_PRODUCTS_TO_CATEGORIES . " where products_id = " . $id);
              while (!$chk_categories->EOF) {
                $product_categories[] = $chk_categories->fields['categories_id'];
                $chk_categories->MoveNext();
              }
              $_POST['product_categories'] = $product_categories;
              if (file_exists(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/delete_product_confirm.php')) {
                require(DIR_WS_MODULES . $zc_products->get_handler($product_type) . '/delete_product_confirm.php');
              } else {
                require(DIR_WS_MODULES . 'delete_product_confirm.php');
              }

              $product_multi = $query;
              $items_set[] = array('id' => $product_multi->fields['products_id'],
                                   'manufacturer' => $product_multi->fields['manufacturers_name'],
                                   'model' => $product_multi->fields['products_model'],
                                   'name' => $product_multi->fields['products_name'],
                                   'price' => zen_get_products_display_price($product_multi->fields['products_id']));
            } // eof delete
          }
        }
        break;
      case 'find':
        $raw_query = "select * from " . TABLE_PRODUCTS . " p left join " . TABLE_MANUFACTURERS . " m on p.manufacturers_id = m.manufacturers_id, " . TABLE_PRODUCTS_DESCRIPTION . " pd, " . TABLE_PRODUCTS_TO_CATEGORIES . " ptoc where p.products_id = pd.products_id and p.products_id = ptoc.products_id and pd.language_id =  " . (int)$_SESSION['languages_id'];
        if (sizeof($products_in_copyto) > 0) $raw_query .= ' and (not (p.products_id in (' . implode(',', $products_in_copyto) . ')))';
        if (is_numeric($manufacturer_id)) $raw_query .= ' and p.manufacturers_id = ' . (int)$manufacturer_id;
        if (is_numeric($category_id)) {
          if ($inc_subcats) {
            $raw_query .= ' and (ptoc.categories_id in (' . (int)$category_id . list_subcategories($category_id) .'))';
          } else {
            $raw_query .= ' and ptoc.categories_id = ' . (int)$category_id;
          }
        }
        if (is_numeric($min_price)) $raw_query .= ' and p.products_price_sorter >= "' . zen_db_input($min_price) . '"';
        if (is_numeric($max_price)) $raw_query .= ' and p.products_price_sorter <= "' . zen_db_input($max_price) . '"';
        if (is_numeric($product_quantity)) $raw_query .= ' and p.products_quantity <= "' . zen_db_input($product_quantity) . '"';

        $where_str = '';
        if (isset($search_keywords) && (sizeof($search_keywords) > 0)) {
          $where_str .= " and (";
          for ($i=0, $n=sizeof($search_keywords); $i<$n; $i++ ) {
            switch ($search_keywords[$i]) {
              case '(':
              case ')':
              case 'and':
              case 'or':
                $where_str .= " " . $search_keywords[$i] . " ";
                break;
              default:
                $keyword = zen_db_prepare_input($search_keywords[$i]);
                $where_str .= "(pd.products_name like '%" . zen_db_input($keyword) . "%' or p.products_model like '%" . zen_db_input($keyword) . "%' or m.manufacturers_name like '%" . zen_db_input($keyword) . "%'";
                if ($within == 'all') $where_str .= " or pd.products_description like '%" . zen_db_input($keyword) . "%'";
                $where_str .= ')';
                break;
            }
          }
          $where_str .= " )";
        }
        $query = $db->Execute($raw_query . $where_str . ' group by p.products_id');
        if ($query->EOF) {
          $action = "new";
          $error = true;
          $messages[] = TEXT_NOT_FOUND;
        }
        break;
    }
  }
?>
<!-- start me here //-->

<!doctype html public "-//W3C//DTD HTML 4.01 Transitional//EN">
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php echo CHARSET; ?>">
<title><?php echo TITLE; ?></title>
<link rel="stylesheet" type="text/css" href="includes/stylesheet.css">
<link rel="stylesheet" type="text/css" href="includes/cssjsmenuhover.css" media="all" id="hoverJS">
<script language="javascript" src="includes/menu.js"></script>
<script language="javascript" src="includes/general.js"></script>
<script type="text/javascript">
  <!--
  function init()
  {
    cssjsmenu('navbar');
    if (document.getElementById)
    {
      var kill = document.getElementById('hoverJS');
      kill.disabled = true;
    }
 }
 // -->
</script>
</head>
<body marginwidth="0" marginheight="0" topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" bgcolor="#FFFFFF" onload="init()">
<div id="spiffycalendar" class="text"></div>
<!-- header //-->
<?php require(DIR_WS_INCLUDES . 'header.php'); ?>
<!-- header_eof //-->

<!-- body //-->
<table border="0" width="100%" cellspacing="2" cellpadding="2">
  <tr>
<!-- body_text //-->
    <td width="100%" valign="top"><table border="0" width="100%" cellspacing="0" cellpadding="10">
      <tr>
        <td width="100%"><table border="0" width="100%" cellspacing="0" cellpadding="0">
          <tr>
            <td class="pageHeading"><?php echo HEADING_TITLE; ?></td>
            <td class="pageHeading" align="right"><?php echo zen_draw_separator('pixel_trans.gif', HEADING_IMAGE_WIDTH, HEADING_IMAGE_HEIGHT); ?></td>
          </tr>
          <?php if (!empty($messages)) { ?>
          <tr>
            <td>
              <?php
              echo '<table ' . ' width="100%">' . "\n";
              foreach ($messages as $message) {
                echo '<tr><td class="alert">' . $message . "</td></tr>\n";
              }
              echo "</table>\n";
              ?>
            </td>
          </tr>
          <?php } ?>
        </table></td>
      </tr>
      <?php if ($action == 'new') {
      echo '<tr><td class="main"><p class="pageHeading">' . HEADING_NEW . "</p>\n";
      echo zen_draw_form('sale_entry', FILENAME_MULTI_COPY, 'action=find');
      echo TEXT_HOW_TO_COPY . "<br />\n";

      echo zen_draw_radio_field('copy_as', 'deleted', ($_POST['copy_as'] == 'deleted' ? true : false)) . ' ' . TEXT_COPY_AS_DELETED . '<br />' . "<br />\n";
      echo zen_draw_radio_field('copy_as', 'move_from', ($_POST['copy_as'] == 'move_from' ? true : false)) . ' ' . TEXT_MOVE_FROM_LINK . "<br />\n";
      echo TEXT_MOVE_PRODUCTS_INFO . "<br />\n";
      echo zen_draw_radio_field('copy_as', 'link', ($_POST['copy_as'] == 'link' || $_POST['copy_as'] == ''  ? true : false)) . ' ' . TEXT_COPY_AS_LINK . "<br />\n";
      echo zen_draw_radio_field('copy_as', 'duplicate', ($_POST['copy_as'] == 'duplicate' ? true : false)) . ' ' . TEXT_COPY_AS_DUPLICATE . "<br />\n";
      echo TEXT_COPYING_DUPLICATES . "<br />\n";
      echo TEXT_COPY_ATTRIBUTES . ' ' . zen_draw_radio_field('copy_attributes', 'copy_attributes_yes', true) . ' ' . TEXT_COPY_ATTRIBUTES_YES . ' ' . zen_draw_radio_field('copy_attributes', 'copy_attributes_no') . ' ' . TEXT_COPY_ATTRIBUTES_NO . "<br />\n";
      echo TEXT_COPY_SPECIALS . ' ' . zen_draw_radio_field('copy_specials', 'copy_specials_yes', true) . ' ' . TEXT_YES . ' ' . zen_draw_radio_field('copy_specials', 'copy_specials_no') . ' ' . TEXT_NO . "<br />\n";
      echo TEXT_COPY_FEATURED . ' ' . zen_draw_radio_field('copy_featured', 'copy_featured_yes', true) . ' ' . TEXT_YES . ' ' . zen_draw_radio_field('copy_featured', 'copy_featured_no') . ' ' . TEXT_NO . "<br />\n";
      echo TEXT_COPY_DISCOUNTS . ' ' . zen_draw_radio_field('copy_discounts', 'copy_discounts_yes', true) . ' ' . TEXT_YES . ' ' . zen_draw_radio_field('copy_discounts', 'copy_discounts_no') . ' ' . TEXT_NO . "<br />\n";
      echo TEXT_COPY_MEDIA_MANAGER . ' ' . zen_draw_radio_field('copy_media', 'on', true) . ' ' . TEXT_YES . ' ' . zen_draw_radio_field('copy_media', 'off') . ' ' . TEXT_NO . "<br /><br />\n";

      echo ENTRY_COPY_TO . ' ' . zen_draw_pull_down_menu('copy_to', zen_get_category_tree('0','','',array(array('id' => '', 'text' => TEXT_SELECT), array('id' => '0', 'text' => TEXT_TOP)))) . ENTRY_COPY_TO_NOTE . '<br>' . "<br>\n";

      echo zen_draw_separator('pixel_black.gif', '90%', '3') . '<br /><br />';

      echo ENTRY_AUTO_CHECK . zen_draw_radio_field('autocheck', 'yes', 'yes') . '&nbsp;' . TEXT_YES . '&nbsp;&nbsp;&nbsp;' . zen_draw_radio_field('autocheck', 'no') . '&nbsp;' . TEXT_NO . "<br><br>\n";

      echo zen_draw_separator('pixel_black.gif', '90%', '3') . '<br /><br />';
      echo '<b>' . TEXT_ENTER_CRITERIA . "</b><br /><br />\n";
      echo TEXT_PRODUCTS_CATEGORY . ' ' . zen_draw_pull_down_menu('category_id', zen_get_category_tree('0','','',array(array('id' => '', 'text' => TEXT_ANY_CATEGORY), array('id' => '0', 'text' => TEXT_TOP))));
      echo '&nbsp;&nbsp;&nbsp;' . zen_draw_checkbox_field('inc_subcats', 'yes') . ENTRY_INC_SUBCATS . ' ' . ENTRY_DELETE_TO_NOTE . "<br>\n";
      echo '<p><b>' . TEXT_ENTER_TERMS . "</b><br /><br />\n";
      echo zen_draw_input_field('keywords', '', 'size=50') . "<br>\n";
      echo zen_draw_radio_field('within', 'name') . '&nbsp;' . TEXT_NAME_ONLY . '&nbsp;' . zen_draw_radio_field('within', 'all', 'all') . '&nbsp;' . TEXT_DESCRIPTIONS . "<br>\n";
      $manufacturers_array = array(array('id' => '', 'text' => TEXT_ANY_MANUFACTURER));
      $manufacturers_query = $db->Execute("select manufacturers_id, manufacturers_name from " . TABLE_MANUFACTURERS . " order by manufacturers_name");
      while (!$manufacturers_query->EOF) {
        $manufacturers_array[] = array('id' => $manufacturers_query->fields['manufacturers_id'],
                                       'text' => $manufacturers_query->fields['manufacturers_name']);
        $manufacturers_query->MoveNext();
      }
      echo TEXT_PRODUCTS_MANUFACTURER . zen_draw_pull_down_menu('manufacturer_id', $manufacturers_array) . "<br>\n";
      echo ENTRY_MIN_PRICE . zen_draw_input_field('min_price') . TEXT_OPTIONAL . "<br>\n";
      echo ENTRY_MAX_PRICE . zen_draw_input_field('max_price') . TEXT_OPTIONAL . "<br>\n";
      echo ENTRY_PRODUCT_QUANTITY . zen_draw_input_field('product_quantity', 'any') . TEXT_OPTIONAL . "<br>\n";
      echo "</p>\n<p>" . zen_image_submit('button_preview.gif', IMAGE_PREVIEW) . '&nbsp;&nbsp;<a href="' . zen_href_link(FILENAME_CATEGORIES) . '">' . zen_image_button('button_cancel.gif', IMAGE_CANCEL) . "</a></form></p>\n";
      echo '<p>' . TEXT_TIPS . "</p>\n";
      ?>
      </form></td></tr>
      <?php } elseif ($action == 'find') {
        ?>
      <tr>
        <td><table border="0" width="100%" cellspacing="0" cellpadding="0">
          <tr>
            <td class="pageHeading">
              <?php
              switch (true) {
                case ($copy_as == 'deleted'):
                  echo HEADING_SELECT_PRODUCT_DELETED;
                  break;
                case ($copy_as == 'move_from'):
                  echo HEADING_SELECT_PRODUCT_MOVE_FROM;
                  break;
                default:
                  echo HEADING_SELECT_PRODUCT;
                  break;
              }
              ?>
            </td>
          </tr>
          <tr>
            <td class="main">&nbsp;</td>
          </tr>
<?php if ($copy_as != 'deleted' && $copy_as != 'link' && $copy_as != 'move_from') { ?>
          <tr>
            <td class="main">
              <?php
              echo TEXT_DUPLICATE_ATTRIBUTES . ($_POST['copy_attributes'] == 'copy_attributes_yes' ? 'YES' : 'NO') . '<br />';
              echo TEXT_DUPLICATE_SPECIALS . ($_POST['copy_specials'] == 'copy_specials_yes' ? 'YES' : 'NO') . '<br />';
              echo TEXT_DUPLICATE_FEATURED . ($_POST['copy_featured'] == 'copy_featured_yes' ? 'YES' : 'NO') . '<br />';
              echo TEXT_DUPLICATE_QUANTITY_DISCOUNTS . ($_POST['copy_discounts'] == 'copy_discounts_yes' ? 'YES' : 'NO') . '<br />';
              echo TEXT_DUPLICATE_MEDIA . ($_POST['copy_media'] == 'on' ? 'YES' : 'NO') . '<br /><br />';
              ?>
            </td>
          </tr>
<?php } // !=deleted ?>
          <tr>
            <td class="main"><p style="font-weight: bold;">
            <?php
//echo '<pre>'; echo var_dump($_POST); echo '</pre>';
            switch ($copy_as) {
              case ('deleted'):
                echo TEXT_DETAILS_DELETED . "</p>\n";
                echo '<blockquote>' . ENTRY_DELETED . "<br>\n";
                echo "</blockquote>\n<b>" . TEXT_SELECT_PRODUCTS_DELETED . "</b>\n";
                break;
              case ('link'):
                echo TEXT_DETAILS_LINK . "</p>\n";
                if (zen_childs_in_category_count($copy_to) > 0) {
                  echo '<span class="alert">' . TEXT_WARNING_CATEGORY_SUB . '</span>' . "<br />\n";
                }
                echo '<blockquote>' . ENTRY_COPY_TO_LINK . $copy_to . ' - ' . $copy_to_name . "<br>\n";
                echo TEXT_ONLY_NOT_DEST . "<br>\n";
                echo "</blockquote>\n<b>" . TEXT_SELECT_PRODUCTS . "</b>\n";
                break;
              default:
              if ($copy_as == 'move_from' && zen_childs_in_category_count($category_id) > 0) {
                echo '<span class="alert">' . TEXT_MOVE_PRODUCTS_CATEGORIES . '</span>'. '<br />' . 'Moving to: ' . $category_id;
              }
              if ($copy_as != 'move_from') {
// echo 'I SEE: ' . $copy_as . '<br>' . 'Copy from: ' . $category_id . '<br>' . 'Copy To: ' . $copy_to . '<br><br><br>';
                echo TEXT_DETAILS . "</p>\n";
                if (zen_childs_in_category_count($copy_to) > 0) {
                  echo '<span class="alert">' . TEXT_WARNING_CATEGORY_SUB . '</span>' . "<br />\n";
                }
                echo '<blockquote>' . ENTRY_COPY_TO_DUPLICATE . $copy_to . ' - ' . $copy_to_name . "<br>\n";
                echo TEXT_ONLY_NOT_DEST . "<br>\n";
                echo "</blockquote>\n<b>" . TEXT_SELECT_PRODUCTS . "</b>\n";
              }
            }
            echo zen_draw_form('select_products', FILENAME_MULTI_COPY, 'action=confirm');
            // repost previous form values
            echo zen_draw_hidden_field('copy_to');
            echo zen_draw_hidden_field('autocheck');
            echo zen_draw_hidden_field('keywords');
            echo zen_draw_hidden_field('within');
            echo zen_draw_hidden_field('manufacturer_id');
            echo zen_draw_hidden_field('category_id');
            echo zen_draw_hidden_field('inc_subcats');
            echo zen_draw_hidden_field('min_price');
            echo zen_draw_hidden_field('max_price');
            echo zen_draw_hidden_field('product_quantity');
            echo zen_draw_hidden_field('copy_as');
            $copy_attributes = $_POST['copy_attributes'];
            echo zen_draw_hidden_field('copy_attributes');
            $copy_specials = $_POST['copy_specials'];
            echo zen_draw_hidden_field('copy_specials');
            $copy_featured = $_POST['copy_featured'];
            echo zen_draw_hidden_field('copy_featured');
            $copy_discounts = $_POST['copy_discounts'];
            echo zen_draw_hidden_field('copy_discounts');
            $copy_media = $_POST['copy_media'];
            echo zen_draw_hidden_field('copy_media');
            ?>
            <table border="1" width="100%" cellspacing="0" cellpadding="2">
              <tr class="dataTableHeadingRow">
                <td class="dataTableHeadingContent" align="center"><?php echo TABLE_HEADING_SELECT; ?></td>
                <td class="dataTableHeadingContent" align="right"><?php echo TABLE_HEADING_PRODUCTS_ID . '&nbsp;&nbsp;'; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_MFG; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_MODEL; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_NAME; ?></td>
                <td class="dataTableHeadingContent" align="right"><?php echo TABLE_HEADING_PRICE; ?>&nbsp;&nbsp;</td>
              </tr>
<?php
$items_found = array();
$cnt = 0;
$product_multi = $query;
while (!$product_multi->EOF) { // list all matching products
?>
              <tr class="dataTableRow" onmouseover="rowOverEffect(this)" onmouseout="rowOutEffect(this)">
                <td class="dataTableContent" align="center">
                <?php
                  echo zen_draw_checkbox_field('product[' . $cnt . ']', $product_multi->fields['products_id'], $autocheck);
                  $items_found[] = $product_multi->fields['products_id'];
                  $cnt++;
                ?>
                </td>
                <td class="dataTableContent" align="right"><?php echo $product_multi->fields['products_id'] . '&nbsp;&nbsp'; ?></td>
                <td class="dataTableContent"><?php echo $product_multi->fields['manufacturers_name'] . '&nbsp;'; ?></td>
                <td class="dataTableContent"><?php echo $product_multi->fields['products_model'] . '&nbsp;'; ?></td>
                <td class="dataTableContent"><?php echo $product_multi->fields['products_name'] . '&nbsp;'; ?></td>
                <td class="dataTableContent" align="right"><?php echo zen_get_products_display_price($product_multi->fields['products_id']); ?>&nbsp;&nbsp;</td>
              </tr>
<?php
  $product_multi->MoveNext();
}
?>
            </table>
<?php
            if ($cnt > 0) {
              echo zen_draw_hidden_field('items_found', implode(',', $items_found));
              echo zen_draw_hidden_field('product_count', $cnt);
              echo $cnt . TEXT_PRODUCTS_FOUND . "<br><br>\n";
              echo zen_image_submit('button_confirm.gif', IMAGE_CONFIRM) . '&nbsp;&nbsp;';
            } else { // no valid products were found
              echo '<p class="error">' . ERROR_NONE_VALID . "</p>\n";
            }
            echo '</form>' . zen_draw_form('retry', FILENAME_MULTI_COPY, 'action=new');
            // repost previous form values
            echo zen_draw_hidden_field('copy_to');
            echo zen_draw_hidden_field('autocheck');
            echo zen_draw_hidden_field('keywords');
            echo zen_draw_hidden_field('within');
            echo zen_draw_hidden_field('manufacturer_id');
            echo zen_draw_hidden_field('category_id');
            echo zen_draw_hidden_field('inc_subcats');
            echo zen_draw_hidden_field('min_price');
            echo zen_draw_hidden_field('max_price');
            echo zen_draw_hidden_field('product_quantity');

            echo zen_draw_hidden_field('copy_as');
            echo zen_draw_hidden_field('copy_attributes');
            echo zen_draw_hidden_field('copy_specials');
            echo zen_draw_hidden_field('copy_featured');
            echo zen_draw_hidden_field('copy_discounts');

            echo zen_draw_hidden_field('copy_media');

            echo zen_draw_input_field('retry', BUTTON_RETRY, 'alt="' . BUTTON_RETRY . '"', false, 'submit');
            echo '&nbsp;&nbsp;<a href="' . zen_href_link(FILENAME_CATEGORIES) . '">' . zen_image_button('button_cancel.gif', IMAGE_CANCEL) . "</a></form>\n";
?>
            </td>
          </tr>
        </table></td>
      </tr>
  <?php
      } else { /* display list of products set */
  ?>
          <tr>
            <td class="pageHeading"><?php echo HEADING_PRODUCT_COPIED; ?></td>
          </tr>
          <tr>
            <td class="main"><p style="font-weight: bold;">
            <?php echo TEXT_DETAILS . "</p>\n";
            echo '<blockquote>' . ENTRY_COPY_TO . $copy_to . ' - ' . $copy_to_name . "<br>\n";
            echo "</blockquote>\n<b>" . TEXT_CHANGES_MADE . "</b>\n";
            ?>
            <table border="0" width="100%" cellspacing="0" cellpadding="2">
              <tr class="dataTableHeadingRow">
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_PRODUCTS_ID; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_MFG; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_MODEL; ?></td>
                <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_NAME; ?></td>
                <td class="dataTableHeadingContent" align="right"><?php echo TABLE_HEADING_PRICE; ?>&nbsp;&nbsp;</td>
              </tr>
              <?php foreach ($items_set as $product_multi) { ?>
              <tr class="dataTableRow" onmouseover="rowOverEffect(this)" onmouseout="rowOutEffect(this)">
                <td class="dataTableContent"><?php echo $product_multi['id']; ?></td>
                <td class="dataTableContent"><?php echo $product_multi['manufacturer']; ?></td>
                <td class="dataTableContent"><?php echo $product_multi['model']; ?></td>
                <td class="dataTableContent"><?php echo $product_multi['name']; ?></td>
                <td class="dataTableContent" align="right"><?php echo $product_multi['price']; ?>&nbsp;&nbsp;</td>
              </tr>
              <?php } ?>
            </table>
            </td>
          </tr>
      <tr><td>
      <?php
      echo sizeof($items_set) . TEXT_PRODUCTS_COPIED . "<p>\n";
      echo zen_draw_form('product_entry', FILENAME_CATEGORIES, 'cPath=' . $copy_to) . zen_draw_input_field('cat', BUTTON_PRODUCT_ENTRY, 'alt="' . BUTTON_PRODUCT_ENTRY . '"', false, 'submit') . '</form>&nbsp;&nbsp;';
      echo zen_draw_form('multi_product_copy', FILENAME_MULTI_COPY) . zen_draw_input_field('new', BUTTON_ANOTHER_COPY, 'alt="' . BUTTON_ANOTHER_COPY . '"', false, 'submit') . "</form>\n";
      } ?>
      </td></tr>
    </table></td>
<!-- body_text_eof //-->
  </tr>
</table>
<!-- body_eof //-->

<!-- footer //-->
<?php require(DIR_WS_INCLUDES . 'footer.php'); ?>
<!-- footer_eof //-->
<br />
</body>
</html>
<?php require(DIR_WS_INCLUDES . 'application_bottom.php'); ?>