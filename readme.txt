=== money1616 iPhone価格テーブル ===
Contributors: money1616
Tags: shortcode, iphone, price, table, block
Requires at least: 5.2
Requires PHP: 7.2
Tested up to: 6.x
Stable tag: 2.0.0
License: GPLv2 or later

Google Drive上の価格JSONを元に、キャリア×iPhone機種の価格テーブルを管理画面で自由に作成し、ショートコード/ブロックで記事に挿入できるプラグイン。

== 主な機能 ==
* 管理画面でテーブルを「プリセット」として作成（機種・キャリア・容量を自由に選択）
* プリセットごとに固有ショートコード [m1616_table id="スラッグ"]
* Gutenbergブロック「iPhone価格テーブル」（一覧から選んで挿入）
* セル＝月額＋実質総額／最安バッジ／推しキャリア強調／在庫表示／CTAボタン
* キャリアの表示名・色・アフィリエイトURLを設定画面で一元管理
* 左サイドバー「iPhone価格」：ダッシュボード／価格テーブル／設定／データ確認
* 毎日7:00、JSONが当日更新ならショートコードを含む記事の更新日(post_modified)を進める
* 後方互換：[iphone_price_table] [iphone_price] [iphone_price_updated_at]

== キャリアを増やすとき（コード編集不要）==
1. 日次スクレイパーがJSONに新キャリアの行を追加
2. 「設定」でそのキャリア（表示名・色・アフィリURL）を登録
3. 各テーブルの編集画面で新キャリアにチェック

== 前提 ==
Drive上の daily_iphone_prices.json を「リンクを知っている全員=閲覧者」で公開しておくこと。

== Changelog ==
= 2.0.0 =
* プリセット型テーブル(CPT)＋ショートコード＋ブロック＋管理画面を追加
= 1.0.0 =
* 初版（簡易ショートコードのみ）
