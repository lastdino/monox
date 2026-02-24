# Monox

Monoxは、Laravel 12およびLivewire 4をベースにした新しい生産管理システム向けパッケージです。

## 特徴

- **生産管理機能**: 生産指示（Production Order）、工程（Process）、BOM（部品表）などの管理。
- **在庫・ロット管理**: ロット（Lot）追跡、在庫移動（Stock Movement）、棚卸機能。
- **受注・出荷管理**: 受注（Sales Order）から出荷（Shipment）までのワークフロー管理。
- **データエクスポート**: PhpSpreadsheetを利用したワークシートや在庫データのExcelエクスポート。
- **メディア管理**: Spatie Laravel Medialibraryによる関連ドキュメントや画像の管理。
- **Livewire対応**: リアクティブなUIコンポーネントによる直感的な操作。
- **アノテーション機能**: 工程ごとに動的な入力フィールド（Production Annotation）を定義し、製造記録時に追加データを収集。

## 依存関係

このパッケージは以下の環境およびパッケージに依存しています。

- **PHP**: ^8.4
- **Laravel Framework**: ^12.0
- **Livewire**: ^4.0
- **PhpSpreadsheet**: ^5.4
- **Spatie Laravel Medialibrary**: ^11.17
- **Spatie Laravel Permission**: ^6.10
- **TuncayBahadir Quar**: ^1.7
- **jsQR**: ^1.4 (npm)
- **FullCalendar**: ^6.1 (npm)

## インストール方法

1. Composerを使用してパッケージをインストールできます。

```bash
composer require lastdino/monox
```

2. 必要な npm パッケージをインストールします。

```bash
npm install jsqr @fullcalendar/core @fullcalendar/daygrid @fullcalendar/interaction @fullcalendar/list @fullcalendar/timegrid
```

3. インストール後、必要に応じてマイグレーションを実行してください。

```bash
php artisan migrate
```

## 設定とビューの公開

Monoxの設定やアセット、ビューを公開してカスタマイズすることができます。

### 設定の公開

```bash
php artisan vendor:publish --tag=monox-config
```

`config/monox.php` が作成されます。ルーティングのプレフィックスやミドルウェアなどを変更できます。

### ビューの公開

```bash
php artisan vendor:publish --tag=monox-views
```

`resources/views/vendor/monox` に公開されます。

## アセット（CSS）の読み込み

プロジェクトに Tailwind がすでにインストールされている場合は、resources/css/app.css ファイルに次の構成を追加するだけです。

```bash
@import '../../vendor/lastdino/monox/dist/monox.css';
```


## カスタマイズ

### ユーザー独自の部門（Department）モデルの使用

Monoxはデフォルトで独自の `Department` モデルを使用しますが、アプリケーション既存のモデルやカスタマイズしたモデルを使用するように設定できます。

1. **モデルの準備**:
   使用するモデルに `Lastdino\Monox\Traits\HasMonoxRelations` トレイトを追加します。これにより、Monoxが必要とする関連付けがモデルに追加されます。

   ```php
   use Illuminate\Database\Eloquent\Model;
   use Lastdino\Monox\Traits\HasMonoxRelations;

   class MyDepartment extends Model
   {
       use HasMonoxRelations;
       // ...
   }
   ```

2. **設定の変更**:
   `config/monox.php` の `models.department` キーを更新して、作成したモデルを指定します。

   ```php
   'models' => [
       'department' => \App\Models\MyDepartment::class,
   ],
   ```

## ルーティング

デフォルトでは `/monox` プレフィックスでルーティングが登録されます。
設定ファイルの `routes.prefix` を変更することで、任意のパスに変更可能です。

主なルート:
- `/monox/{department}/production`: 製造記録
- `/monox/{department}/analytics`: 製造分析ダッシュボード
- `/monox/{department}/inventory/lot-summary`: 在庫サマリー

## 検査データ連携 API (AIP)

外部システムや検査機器から、シリアル番号（SN）ごとの全数検査データを取り込むための API を提供しています。

### エンドポイント

`POST /api/monox/v1/inspection/sync`

### データ構造

リクエストボディの例：

```json
{
    "lot_number": "LOT20260224-01",
    "process_name": "全数検査工程",
    "inspections": [
        {
            "sn": "SN001",
            "measurements": {
                "length": 100.5,
                "diameter": 10.2
            },
            "is_good": true
        },
        {
            "sn": "SN002",
            "measurements": {
                "length": 101.0,
                "diameter": 10.3
            },
            "is_good": false,
            "note": "全長オーバー"
        }
    ]
}
```

### 仕様

- **データの紐付け**: `lot_number` と `process_name` に基づき、対応する `ProductionRecord`（製造実績）に関連付けて保存されます。
- **アノテーション項目の利用**: 各測定値（`measurements` 内のキー）は、マスタで定義された `field_key` と照合され、`ProductionAnnotationValue` として保存されます。
- **重複データの扱い（上書き）**: 同じロット・同じ工程において、同一のシリアル番号（SN）を持つデータが既に存在する場合、既存のレコードは新しいデータで上書き（更新）されます。
- **分割送信**: 1つのロットに対して、複数回に分けて検査データを送信することが可能です。送信のたびにロットの統計値が再計算され、ダッシュボード等のトレンド表示に反映されます。

## ライセンス

このパッケージはMITライセンスの下で公開されています。
