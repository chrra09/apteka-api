<?
require_once $_SERVER['DOCUMENT_ROOT'] . '/api/config.php';


function base64url_encode($data)
{
  $b64 = base64_encode($data);
  if ($b64 === false) {
    return false;
  }
  $url = strtr($b64, '+/', '-_');
  return rtrim($url, '=');
}


function generate_uuid($str){
	$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5(microtime() . $str), 4));
	return $uuid;
}


function timezone_process ($input) {
	$str = str_replace("%2B", "+", $input);	
	if (strpos($str,"+")) {
 		$first_part = substr($str,0,strpos($str,"+"));
		$second_part = substr($str,strpos($str,"+")+1);
		if (strpos($second_part,":") !== FALSE) {
			$second_part = substr($second_part,0,strpos($second_part,":"));
		}
		return date('Y-m-d H:i:s',strtotime($first_part . " - " . $second_part . " hour"));

	} else {
		return $str;
	}
}



// запрос токена
if ($this->request->request['route'] == "api/login/token" && $this->request->server['REQUEST_METHOD'] == "POST") { 

	$error = Array();
	$client_id = "";
	$client_secret = "";

	if (isset($this->request->post['client_id'])) {
		if ($this->request->post['client_id'] !== "") {
			$client_id = $this->request->post['client_id'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (isset($this->request->post['client_secret'])) {
		if ($this->request->post['client_secret'] !== "") {
			$client_secret = $this->request->post['client_secret'];
		} else {
			$error[] = "Ошибка: Пустой пароль аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует пароль аптеки.";
	}

	if (isset($this->request->post['grant_type'])) {
		if ($this->request->post['grant_type'] != "client_credentials") {
			$error[] = "Ошибка: Неверный параметр grant_type.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует параметр grant_type.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $client_id . ";");

		if (!$store->num_rows) {
			$error[] = "Ошибка: Не найден идентификатор аптеки.";
		} else {
			if ($store->row['comment'] != $client_secret) {
				$error[] = "Ошибка: Неверный пароль аптеки.";
			} 
		}
	}

	if (!count($error)) {
		$jwt_key = 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjdGQzk0RDU4N0UzQzg4QUM0M0FD';
		$jwt_header = '{"alg": "HS256", "typ": "JWT"}';
		$jwt_payload = '{"iss": "' . $this->request->server['REMOTE_ADDR'] . '", "name": "'. $client_id .'", "iat": "' . time() . '"}';
		$jwt_signature = hash_hmac('sha256', base64url_encode($jwt_header) . '.' . base64url_encode($jwt_payload), $jwt_key);
		$jwt = base64url_encode($jwt_header) . '.' . base64url_encode($jwt_payload) . '.' . base64url_encode($jwt_signature);
		$expire = time() + $apiTokenTTL;

		$this->db->query("UPDATE oc_location SET token = '" . $jwt . "', expire = '" . $expire . "' WHERE fax = " . $client_id . ";");
		header("Content-type: application/json");
		header("Vary: Accept");
		http_response_code(200);

		echo '{ "access_token": "' . $jwt . '", "expires_in": ' . $apiTokenTTL . ', "token_type": "Bearer" }';
	} else {
		http_response_code(400);
		foreach($error as $row) {
			echo($row . "\n");
		}
	}


// передача остатков, полных или частичных
} elseif ($this->request->request['route'] == "api/stores/stocks" && ($this->request->server['REQUEST_METHOD'] == "POST" || $this->request->server['REQUEST_METHOD'] == "PUT")) { 

	$error = Array();
	$store_id = 0;
	$token = explode("Bearer ", $this->request->server['HTTP_AUTHORIZATION']);
	$token_issue = 0;

	if (!isset($token[1])) {
		$error[] = "Ошибка: Отсутствует токен.";
	} else {
		$token = $token[1];
	}

	if (isset($this->request->get['storeId'])) {
		if ($this->request->get['storeId'] !== "") {
			$store_id = $this->request->get['storeId'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $store_id . ";");
		if (!$store->num_rows) {
			$error[] = "Ошибка: Аптека с таким идентификатором не найдена.";
		} else {
			if ($store->row['token'] != $token) {
				$error[] = "Ошибка: Неверный токен.";
				$token_issue = 1;
			} elseif ($store->row['expire'] < time()) {
				$error[] = "Ошибка: Просроченый токен.";
				$token_issue = 1;
			}
		}
	}

	if (!count($error)) {

		$json = json_decode(file_get_contents('php://input'));
		if ($json) {
			if (!isset($json->date)) {
				http_response_code(400);
				echo("Ошибка: Отсутствует JSON параметр date.");
			} else {
				$update_date = $json->date;
				if (!isset($json->stocks)) {
					http_response_code(400);
					echo("Ошибка: Отсутствует JSON параметр stocks.");
				} else {
					$values = "";
					foreach($json->stocks as $item) {
						if (isset($item->nnt) && isset($item->qnt) && isset($item->prcRet)) {
							$nnt = $item->nnt;
							if (!$nnt) {
								$error = 1;
							}
							$qnt = $item->qnt;
							$price = $item->prcRet;
							$price_s = 0;
							if (isset($item->prcSel)) {
								$price_s = $item->prcSel;
							}
							$series = "";
							if (isset($item->series)) {
								$series = $item->series;
							}
							$barcode = "";
							if (isset($item->Barcode)) {
								$barcode = $item->Barcode;
							}
							$coming_date = "";
							if (isset($item->dtComing)) {
								$coming_date = $item->dtComing;
							}
							if ($values) {
								$row = ", (";
							} else {
								$row = "(";
							}
							$row .= "'" . $nnt . "', '" . $store_id . "', '" . $price . "', '" . $price_s . "', '" . $qnt . "', '" . $series . "', '" . $barcode . "', '" . timezone_process($coming_date) . "', '" . timezone_process($update_date) . "')";
							$values .= $row;

						} else {
							$error = 1;
						}
					}

					if ($error) {
						http_response_code(400);
						echo("Ошибка: Отсутствуют обязательные поля у элементов массива stocks.");
					} else {
						http_response_code(202);
//						print_r($values);
						if ($values) {
							if ($this->request->server['REQUEST_METHOD'] == "POST") {
								$this->db->query("UPDATE oc_product_location SET quantity = 0 WHERE location_code = " . $store_id . ";");
							}
							$this->db->query("INSERT INTO oc_product_location (product_id, location_code, price, price_s, quantity, series, barcode, coming_date, update_date) VALUES " . $values . " ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), location_code = VALUES(location_code), price = VALUES(price), price_s = VALUES(price_s), quantity = VALUES(quantity), series = VALUES(series), barcode = VALUES(barcode), coming_date = VALUES(coming_date), update_date = VALUES(update_date);");
						}
    				}
				}
			}

		} else {
			http_response_code(400);
			echo("Ошибка JSON.");
		}

	} else {
		if ($token_issue) {
			http_response_code(401);
		} else {
			http_response_code(400);
		}
		foreach($error as $row) {
			echo($row . "\n");
		}
	}



// получение остатка по товару
} elseif ($this->request->request['route'] == "api/stores/stocks" && $this->request->server['REQUEST_METHOD'] == "GET") { 

	$error = Array();
	$store_id = 0;
	$token = explode("Bearer ", $this->request->server['HTTP_AUTHORIZATION']);
	$token_issue = 0;

	if (!isset($token[1])) {
		$error[] = "Ошибка: Отсутствует токен.";
	} else {
		$token = $token[1];
	}

	if (isset($this->request->get['storeId'])) {
		if ($this->request->get['storeId'] !== "") {
			$store_id = $this->request->get['storeId'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $store_id . ";");
		if (!$store->num_rows) {
			$error[] = "Ошибка: Аптека с таким идентификатором не найдена.";
		} else {
			if ($store->row['token'] != $token) {
				$error[] = "Ошибка: Неверный токен.";
				$token_issue = 1;
			} elseif ($store->row['expire'] < time()) {
				$error[] = "Ошибка: Просроченый токен.";
				$token_issue = 1;
			}
		}
	}

	if (!count($error)) {

		if (isset($this->request->get['nnt'])) {
			if ($this->request->get['nnt'] !== "") {
				$nnt = $this->request->get['nnt'];
			} else {
				$error[] = "Ошибка: Пустой ID товара.";
			}	
		} else {
			$error[] = "Ошибка: Отсутствует ID товара.";
		}

		if (!count($error)) {
			$query_name = $this->db->query("SELECT name FROM oc_product_description WHERE product_id = " . $nnt . ";");
			$query = $this->db->query("SELECT * FROM oc_product_location WHERE product_id = " . $nnt . " AND location_code = " . $store_id  . ";");

			$output = Array();
			$output["nnt"] = $query->row["product_id"];
			$output["qnt"] = $query->row["quantity"];
			$output["prcRet"] = round($query->row["price"],2);
			$output["name"] = ((isset($query_name->row["name"])) ? $query_name->row["name"] : "");

			http_response_code(200);
			echo json_encode($output, JSON_UNESCAPED_UNICODE);			
		}	


	} else {
		if ($token_issue) {
			http_response_code(401);
		} else {
			http_response_code(400);
		}
		foreach($error as $row) {
			echo($row . "\n");
		}
	}




// получение изменений по заказам
} elseif ($this->request->request['route'] == "api/exchanger" && $this->request->server['REQUEST_METHOD'] == "GET") { 

	$error = Array();
	$store_id = 0;
	$token = explode("Bearer ", $this->request->server['HTTP_AUTHORIZATION']);
	$token_issue = 0;

	if (!isset($token[1])) {
		$error[] = "Ошибка: Отсутствует токен.";
	} else {
		$token = $token[1];
	}

	if (isset($this->request->get['storeId'])) {
		if ($this->request->get['storeId'] !== "") {
			$store_id = $this->request->get['storeId'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (isset($this->request->get['since'])) {
		if ($this->request->get['since'] !== "") {
			$since = timezone_process($this->request->get['since']);
		} else {
			$error[] = "Ошибка: Пустая дата отсчета.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует дата отсчета.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $store_id . ";");
		if (!$store->num_rows) {
			$error[] = "Ошибка: Аптека с таким идентификатором не найдена.";
		} else {
			if ($store->row['token'] != $token) {
				$error[] = "Ошибка: Неверный токен.";
				$token_issue = 1;
			} elseif ($store->row['expire'] < time()) {
				$error[] = "Ошибка: Просроченый токен.";
				$token_issue = 1;
			}
		}
	}

	if (!count($error)) {

		$output = Array();

		$query = $this->db->query("SELECT order_id, store_order_id, store_code, store_order_num, date_added, firstname, lastname, telephone, shipping_code, shipping_address_1, shipping_address_2, date_modified FROM oc_order WHERE date_added > '" . $since . "' AND store_code = '" . $store_id . "' ORDER BY date_added ASC ;");
		$output["headers"] = Array();
		foreach($query->rows as $row) {
			$tmp = Array();
			$tmp["orderId"] = $row['store_order_id'];
			$tmp["storeId"] = $row['store_code'];
			$tmp["src"] = "desktop";
			$tmp["num"] = $row['store_order_num'];
			$tmp["date"] = $row['date_added'];
			$tmp["name"] = $row['firstname'] . " " . $row['lastname'];
			$tmp["mPhone"] = $row['telephone'];
			$tmp["OrderType"] = 'pickup';
            		$tmp["Adress"] = "";
            		$tmp["AdressAdd"] = "";
            		$tmp["ShippingCost"] = 0;
			if (strpos($row['shipping_code'], 'xshipping') === false) {
				$tmp["OrderType"] = 'delivery';
				$tmp["Adress"] = $row['shipping_address_1'];
				$tmp["AdressAdd"] = $row['shipping_address_2'];
				$deliveryQuery = $this->db->query("SELECT value FROM oc_order_total WHERE order_id = '" . $row['order_id'] . "' AND code = 'shipping' ;");
				if ($deliveryQuery->row['value']) {
					$tmp["ShippingCost"] = round($deliveryQuery->row['value'], 2);
				}
			}
			$tmp["PaymentType"] = $tmp["OrderType"];
			$statusQuery = $this->db->query("SELECT order_history_id FROM oc_order_history WHERE order_id = '" . $row['order_id'] . "' AND order_status_id = '27' ;");
			if ($statusQuery->num_rows) {
				$tmp["PaymentType"] = 'online';
			}
			$tmp["ts"] = $row['date_modified'];
			$output["headers"][] = $tmp;
		}

		$query = $this->db->query("SELECT a.row_id, b.store_order_id, a.product_id, b.store_code, a.quantity, c.price, a.ts, b.date_added, c.price_s, c.barcode FROM oc_order_product as a, oc_order as b, oc_product_location as c WHERE b.date_added > '" . $since . "' AND b.store_code = '" . $store_id . "' AND a.order_id = b.order_id AND c.product_id = a.product_id AND c.location_code = '" . $store_id . "' ORDER BY b.date_added ASC ;");

		$output["rows"] = Array();
		foreach($query->rows as $row) {
			$tmp = Array();
			$tmp["rowId"] = $row['row_id'];
			$tmp["orderId"] = $row['store_order_id'];
			$tmp["rowType"] = 0;
			$tmp["nnt"] = $row['product_id'];
			$tmp["qnt"] = $row['quantity'];
			$tmp["prc"] = $row['price'];
			$tmp["Barcode"] = $row['barcode'];
			$tmp["prcSel"] = $row['price_s'];
			$tmp["ts"] = $row['ts'];
			$output["rows"][] = $tmp;
		}

		$query = $this->db->query("SELECT a.status_id, b.store_order_id, a.row_id, b.store_code, a.date, a.status, a.rc_date, a.cmnt, a.ts FROM oc_order_statuses as a, oc_order as b WHERE a.date > '" . $since . "' AND b.store_code = '" . $store_id . "' AND a.order_id = b.order_id ORDER BY a.date ASC ;");
		$output["statuses"] = Array();

		foreach($query->rows as $row) {
			$tmp = Array();
			$tmp["statusId"] = $row['status_id'];
			$tmp["orderId"] = $row['store_order_id'];
			if ($row['row_id']) {
				$tmp["rowId"] = $row['row_id'];
			}
			$tmp["storeId"] = $row['store_code'];
			$tmp["date"] = $row['date'];
			$tmp["status"] = $row['status'];
			if ($row['rc_date']) {
				$tmp["rcDate"] = $row['rc_date'];
			}
			if ($row['cmnt']) {
				$tmp["cmnt"] = $row['cmnt'];
			}
			$tmp["ts"] = $row['ts'];
			$output["statuses"][] = $tmp;
		}
		if (!count($output["headers"])) unset($output["headers"]);
		if (!count($output["rows"])) unset($output["rows"]);
		if (!count($output["statuses"])) unset($output["statuses"]);

		http_response_code(200);
		echo json_encode($output, JSON_UNESCAPED_UNICODE);			


	} else {
		if ($token_issue) {
			http_response_code(401);
		} else {
			http_response_code(400);
		}
		foreach($error as $row) {
			echo($row . "\n");
		}
	}



// передача статусов заказов
} elseif ($this->request->request['route'] == "api/exchanger" && $this->request->server['REQUEST_METHOD'] == "PUT") { 

	$error = Array();
	$store_id = 0;
	$token = explode("Bearer ", $this->request->server['HTTP_AUTHORIZATION']);
	$token_issue = 0;

	if (!isset($token[1])) {
		$error[] = "Ошибка: Отсутствует токен.";
	} else {
		$token = $token[1];
	}

	if (isset($this->request->get['storeId'])) {
		if ($this->request->get['storeId'] !== "") {
			$store_id = $this->request->get['storeId'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $store_id . ";");
		if (!$store->num_rows) {
			$error[] = "Ошибка: Аптека с таким идентификатором не найдена.";
		} else {
			if ($store->row['token'] != $token) {
				$error[] = "Ошибка: Неверный токен.";
				$token_issue = 1;
			} elseif ($store->row['expire'] < time()) {
				$error[] = "Ошибка: Просроченый токен.";
				$token_issue = 1;
			}
		}
	}

	if (!count($error)) {

		$json = json_decode(file_get_contents('php://input'));
		if ($json) {
			$rows1 = "";
			$rows2 = "";
			$rows3 = "";
			$rows4 = "";
			if (isset($json->rows)) {
				foreach($json->rows as $item) {
					if (isset($item->rowId) && isset($item->qntUnrsv)) {
						$rowId = $item->rowId;
						if (!$rowId) {
							$error = 1;
						}
						$qntUnrsv = $item->qntUnrsv;
						if (!$qntUnrsv) {
							$qntUnrsv = 0;
						}

						$row = "WHEN row_id = '" . $rowId . "' THEN " . $qntUnrsv . " ";
						$rows1 .= $row;

						if ($rows2) {
							$row = ", ";
						} else {
							$row = "";
						}
						$row .= "'" . $rowId . "'";
						$rows2 .= $row;

					} else {
						$error = 1;
					}
				}
				if ($error) {
					http_response_code(400);
					echo("Ошибка: Отсутствуют обязательные поля у элементов массива rows.");
				}
			}
			$statuses = "";
			$orders = "";
			if (isset($json->statuses)) {

				$this->load->model('checkout/order');

				foreach($json->statuses as $item) {
					if (isset($item->statusId) && isset($item->orderId) && isset($item->storeId) && isset($item->date) && isset($item->status)) {
						$statusId = $item->statusId;
						if (!$statusId) {
							$error = 2;
						}
						$orderId = $item->orderId;
						if (!$orderId) {
							$error = 2;
							$orderId = 0;
						}
						$query = $this->db->query("SELECT order_id FROM oc_order WHERE store_order_id = '" . $orderId . "'");
						if (isset($query->row['order_id'])) {
							$orderId = $query->row['order_id'];
						} else {
							$error = 3;
							$orderId = 0;
						}
						$rowId = "";
						if (isset($item->rowId)) {
							$rowId = $item->rowId;
						}
						$storeId = $item->storeId;
						if (!$storeId) {
							$error = 2;
						}
						$date = $item->date;
						if (!$date) {
							$error = 2;
						}
						$status = $item->status;
						if (!$status) {
							$error = 2;
						}
						$rcDate = 0;
						if (isset($item->rcDate)) {
							$rcDate = $item->rcDate;
						}
						$cmnt = "";
						if (isset($item->cmnt)) {
							$cmnt = $item->cmnt;
						}
						if ($statuses) {
							$row = ", (";
						} else {
							$row = "(";
						}
						$row .= "'" . $statusId . "', '" . $orderId . "', '" . $rowId . "', '" . $storeId . "', '" . timezone_process($date) . "', '" . $status . "', '" . timezone_process($rcDate) . "', '" . $cmnt . "', NOW())";
						$statuses .= $row;

						if ($orderId && ($status == 200 || $status == 201 || $status == 202 || $status == 205 || $status == 210 || $status == 212 || $status == 214 || $status == 215 || $status == 216)) {
							
							if ($status == 200)
								$status = 2;
							if ($status == 201)
								$status = 21;
							if ($status == 202)
								$status = 22;
							if ($status == 205)
								$status = 23;
							if ($status == 210)
								$status = 3;
							if ($status == 212)
								$status = 24;
							if ($status == 214)
								$status = 25;
							if ($status == 215)
								$status = 26;
							if ($status == 216)
								$status = 28;

							$row = "WHEN order_id = '" . $orderId . "' THEN " . $status . " ";
							$rows3 .= $row;

							if ($rows4) {
								$row = ", ";
							} else {
								$row = "";
							}
							$row .= "'" . $orderId . "'";
							$rows4 .= $row;

							if ($orders) {
								$row = ", (";
							} else {
								$row = "(";
							}
							$row .= "'" . $orderId . "', '" . $status . "', NOW())";
							$orders .= $row;

							$this->model_checkout_order->sendStatusNotification($orderId, $status, true);

						}

					} else {
						$error = 2;
					}
				}
				if ($error == 2) {
					http_response_code(400);
					echo("Ошибка: Отсутствуют обязательные поля у элементов массива statuses.");
				}
				if ($error == 3) {
					http_response_code(400);
					echo("Ошибка: Не найден заказ с orderId, указанным в массиве statuses.");
				}
			}
			if (!$error){

				http_response_code(200);
				if ($rows1) {
					$this->db->query("UPDATE oc_order_product SET unserved = CASE " . $rows1 . " ELSE unserved END WHERE row_id IN (" . $rows2 . ")");
				}
				if ($statuses) {
					$this->db->query("INSERT INTO oc_order_statuses (status_id, order_id, row_id, store_code, date, status, rc_date, cmnt, ts) VALUES " . $statuses . ";");
				}
				if ($rows3) {
					$this->db->query("UPDATE oc_order SET order_status_id = CASE " . $rows3 . " ELSE order_status_id END WHERE order_id IN (" . $rows4 . ")");
				}
				if ($orders) {
					$this->db->query("INSERT INTO oc_order_history (order_id , order_status_id, date_added) VALUES " . $orders . ";");
				}

			}

		} else {
			http_response_code(400);
			echo("Ошибка JSON.");
		}

	} else {
		if ($token_issue) {
			http_response_code(401);
		} else {
			http_response_code(400);
		}
		foreach($error as $row) {
			echo($row . "\n");
		}
	}



// передача рекомендуемых товаров
} elseif ($this->request->request['route'] == "api/ProductGroup" && $this->request->server['REQUEST_METHOD'] == "POST") { 

	$error = Array();
	$store_id = 0;
	$token = explode("Bearer ", $this->request->server['HTTP_AUTHORIZATION']);
	$token_issue = 0;

	if (!isset($token[1])) {
		$error[] = "Ошибка: Отсутствует токен.";
	} else {
		$token = $token[1];
	}

	if (isset($this->request->get['storeId'])) {
		if ($this->request->get['storeId'] !== "") {
			$store_id = $this->request->get['storeId'];
		} else {
			$error[] = "Ошибка: Пустой идентификатор аптеки.";
		}	
	} else {
		$error[] = "Ошибка: Отсутствует идентификатор аптеки.";
	}

	if (!count($error)) {
		$store = $this->db->query("SELECT * FROM oc_location WHERE fax = " . $store_id . ";");
		if (!$store->num_rows) {
			$error[] = "Ошибка: Аптека с таким идентификатором не найдена.";
		} else {
			if ($store->row['token'] != $token) {
				$error[] = "Ошибка: Неверный токен.";
				$token_issue = 1;
			} elseif ($store->row['expire'] < time()) {
				$error[] = "Ошибка: Просроченый токен.";
				$token_issue = 1;
			}
		}
	}

	if (!count($error)) {

		$json = json_decode(file_get_contents('php://input'));
		if ($json) {
			if (!isset($json->Groups)) {
				http_response_code(400);
				echo("Ошибка: Отсутствует JSON параметр Groups.");
			} else {
				foreach($json->Groups as $item) {
					if (isset($item->nnt) && isset($item->GroupName)) {
						$nnt = $item->nnt;
						$GroupName = $item->GroupName;
						if (!$GroupName) {
							$error = 1;
						}

						$query = $this->db->query("SELECT module_id, setting FROM oc_module WHERE name = '" . $GroupName . "' AND code = 'featured';");
						if ($query->num_rows == 0) {
							$error = 2;
							break;
						}
						$modId = json_decode($query->row['module_id']);
						$result = json_decode($query->row['setting']);
						$result->product = $nnt;
						$result = json_encode($result, JSON_UNESCAPED_UNICODE);

						$this->db->query("UPDATE oc_module SET setting = '" . $result . "' WHERE module_id = " . $modId . ";");

					} else {
						$error = 1;
					}
				}

				if ($error == 1) {
					http_response_code(400);
					echo("Ошибка: Отсутствуют обязательные поля у элементов массива Groups.");
				} elseif($error == 2) {
					http_response_code(400);
					echo("Ошибка: Значение GroupName '" . $GroupName . "' отсутствует среди имен модулей.");
				} else {
					http_response_code(200);
				}
			}

		} else {
			http_response_code(400);
			echo("Ошибка JSON.");
		}

	} else {
		if ($token_issue) {
			http_response_code(401);
		} else {
			http_response_code(400);
		}
		foreach($error as $row) {
			echo($row . "\n");
		}
	}



// неправильный запрос
} else {
	http_response_code(400);
	echo ("Ошибка: Недопустимый запрос.");
}




