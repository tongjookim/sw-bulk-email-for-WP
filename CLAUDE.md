# SW Bulk Email & Opt-in — 플러그인 개발 문서

## 개요

WordPress 뉴스레터 구독 및 대량 메일 발송 플러그인.  
WP-Members + 코스모스팜 회원관리 스킨 환경에서 운영.  
WP Mail SMTP를 실제 발송 트랜스포트로 사용.

- **플러그인 버전**: `1.0.3` (`SW_BULK_EMAIL_VERSION`)
- **구독자 테이블**: `wp_sw_subscribers`
- **템플릿 테이블**: `wp_sw_email_templates`
- **아카이브 테이블**: `wp_sw_email_archive`
- **푸터 옵션 키**: `sw_bulk_email_footer`
- **임베드 토큰 옵션 키**: `sw_bulk_email_embed_token`

---

## 파일 구조

```
sw-bulk-email/
├── sw-bulk-email.php                         # 메인 부트스트랩
├── admin/
│   ├── class-sw-admin.php                    # 어드민 UI (작성/발송/구독자 목록/아카이브 AJAX)
│   ├── class-sw-archive-page.php             # 발송 내역 목록·편집 페이지
│   └── class-sw-footer-settings.php          # 메일 푸터 설정 페이지
├── includes/
│   ├── class-sw-db.php                       # DB 헬퍼 (정적 메서드)
│   ├── class-sw-archive-shortcode.php        # [sw_email_archive] 숏코드
│   ├── class-sw-email-footer.php             # 메일 푸터 HTML 빌더
│   ├── class-sw-mailer.php                   # wp_mail() 래퍼
│   ├── class-sw-optin.php                    # 숏코드/임베드 구독 폼
│   ├── class-sw-unsubscribe.php              # 수신 거부 처리
│   └── class-sw-wp-members-integration.php  # WP-Members 연동
└── public/
    ├── css/sw-public.css                     # 공개 페이지 스타일 (아카이브 포함)
    ├── assets/
    │   └── icon/                             # 소셜 아이콘 PNG (흰색, 투명 배경)
    │       ├── facebook-brands-solid.png
    │       ├── x-twitter-brands-solid.png
    │       ├── instagram-brands-solid.png
    │       └── linkedin-brands-solid.png
    └── embed-form-template.php               # 외부 임베드용 폼 템플릿
```

---

## DB 스키마

### `wp_sw_subscribers`

| 컬럼 | 타입 | 설명 |
|---|---|---|
| id | BIGINT UNSIGNED AI | PK |
| email | VARCHAR(200) UNIQUE | 구독자 이메일 |
| confirmed | TINYINT(1) | 더블 옵트인 완료 여부 |
| ad_opt_in | TINYINT(1) | 광고 수신 동의 여부 |
| opt_in_date | DATETIME | 구독 확인일 |
| unsubscribe_token | VARCHAR(64) UNIQUE | 수신 거부 토큰 |
| created_at | DATETIME | 생성일 |

> **주의**: `ad_opt_in` 컬럼은 나중에 추가됨. 기존 설치에서 없을 경우 ALTER TABLE로 직접 추가 필요.
> ```sql
> ALTER TABLE wp_sw_subscribers ADD COLUMN ad_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmed;
> ```

### `wp_sw_email_templates`

| 컬럼 | 타입 | 설명 |
|---|---|---|
| id | BIGINT UNSIGNED AI | PK |
| template_name | VARCHAR(200) | 템플릿 이름 |
| subject | TEXT | 메일 제목 |
| body | LONGTEXT | 메일 본문 (HTML) |
| mail_type | VARCHAR(20) | 'subscriber' 또는 'system' |
| created_at | DATETIME | 생성일 |

---

## 클래스 레퍼런스

### `SW_DB` (정적 메서드)

```php
// 구독자
SW_DB::add_subscriber( string $email, string $token, bool $ad_opt_in = false )
SW_DB::confirm_subscriber( string $token ): bool
SW_DB::delete_by_token( string $token ): bool
SW_DB::delete_by_email( string $email ): bool
SW_DB::delete_by_id( int $id ): bool
SW_DB::get_by_email( string $email ): ?array
SW_DB::get_confirmed_subscribers( int $limit, int $offset ): array
SW_DB::count_confirmed(): int
SW_DB::get_confirmed_ad_subscribers( int $limit, int $offset ): array
SW_DB::count_confirmed_ad_subscribers(): int
SW_DB::get_all_subscriber_emails(): string[]
SW_DB::set_ad_opt_in_by_email( string $email, bool $status ): bool

// 템플릿
SW_DB::save_template( string $name, string $subject, string $body, string $mail_type ): int
SW_DB::get_template( int $id ): ?array
SW_DB::get_templates( string $mail_type ): array
SW_DB::delete_template( int $id ): bool

// 아카이브
SW_DB::create_archive_table(): void
SW_DB::archive_save( string $subject, string $body, string $mail_type, string $status = 'sent' ): int
SW_DB::archive_update_stats( int $id, int $sent, int $failed ): bool
SW_DB::archive_update_content( int $id, string $subject, string $body ): bool
SW_DB::archive_update_status( int $id, string $status ): bool
SW_DB::archive_get( int $id ): ?array
SW_DB::archive_list( int $limit, int $offset ): array
SW_DB::archive_count(): int
SW_DB::archive_delete( int $id ): bool
SW_DB::archive_list_public( int $limit, int $offset ): array
SW_DB::archive_count_public(): int
SW_DB::archive_toggle_public( int $id, bool $public ): bool
```

### `SW_Mailer` (정적 메서드)

```php
SW_Mailer::send( string $to, string $subject, string $body ): bool
// → 메일 푸터(공시 정보) 자동 첨부, 수신 거부 링크 없음 (시스템/테스트 메일용)

SW_Mailer::send_subscribed( string $to, string $subject, string $body, string $token ): bool
// → 메일 푸터 + 수신 거부 링크 자동 첨부 (구독자/광고 메일용)

SW_Mailer::get_sender_info(): array
// → { from_email, from_name, force_email, force_name, smtp_active }
```

### `SW_Email_Footer` (정적 메서드)

```php
SW_Email_Footer::get_html( string $unsubscribe_url = '' ): string
// → 저장된 설정 기반 HTML 푸터 반환. $unsubscribe_url 있으면 수신 거부 블록 포함.

SW_Email_Footer::get_settings(): array
// → { business:{ceo,address,reg_no,phone}, links:[{label,url}], social:{...}, custom_social:[...] }

SW_Email_Footer::save_settings( array $raw ): void
// → sanitize 후 wp_options 저장
```

---

## WP-Members 연동 (`SW_WP_Members_Integration`)

### 핵심 주의 사항

- **훅 이름**: `wpmem_fields` 사용 (`wpmem_register_fields`는 존재하지 않는 가짜 훅)
- **훅 파라미터**: `wpmem_post_register_data`는 `array $post_data`를 전달 (int $user_id가 **아님**)
  - `$post_data['ID']` = 신규 user_id
  - `$post_data['user_email']` = 이메일
  - `$post_data['sw_subscribe_newsletter']` = `'1'` 또는 `''`
  - `$post_data['sw_subscribe_ad']` = `'1'` 또는 `''`
- **태그 정규화**: WP-Members는 `'new'` → `'register'`, `'edit'` → `'profile'`로 변환 후 필터 발동
- **코스모스팜 스킨**: `wpmem_register_fields` 필터를 무시하고 `wpmem_fields` + `wpmem_register_form_rows`로 직접 렌더링

### 등록된 훅

| 훅 | 메서드 | 역할 |
|---|---|---|
| `wpmem_fields` | `add_fields` | 가입/수정 폼에 체크박스 필드 주입 |
| `wpmem_post_register_data` | `handle_registration` | 가입 시 구독자 DB 저장 + user meta 저장 |
| `wpmem_post_update_data` | `handle_profile_update` | 회원정보 수정 시 구독자 DB 동기화 |

### 프로필 수정 동작

- 뉴스레터 체크 유지 → `set_ad_opt_in_by_email()` 호출 (광고 동의 갱신)
- 뉴스레터 체크 해제 → `delete_by_email()` 호출 (구독자 목록에서 제거)
- 기존 구독자(user meta 미설정) → 프로필 페이지 접근 시 DB 기반으로 user meta 자동 설정 (lazy migration)

### WP-Members 필드 정의 구조

```php
[
    'label'           => '레이블',
    'type'            => 'checkbox',
    'checked_value'   => '1',
    'checked_default' => false,
    'register'        => true,   // 가입 폼 표시 여부
    'required'        => false,
    'profile'         => true,   // 정보수정 폼 표시 여부
    'native'          => false,
]
```

---

## 메일 푸터 설정

**관리자 메뉴**: SW Bulk Email → 메일 푸터 설정

### 저장 구조 (wp_options: `sw_bulk_email_footer`)

```php
[
    'business' => [
        'ceo'     => '대표자명',
        'address' => '주소',
        'reg_no'  => '사업자번호',
        'phone'   => '대표번호',
    ],
    'links' => [
        ['label' => '링크 이름', 'url' => 'https://...'],
    ],
    'social' => [
        'facebook'  => 'https://...',
        'twitter'   => 'https://...',
        'instagram' => 'https://...',
        'linkedin'  => 'https://...',
    ],
    'custom_social' => [
        ['icon' => 'https://example.com/icon.png', 'label' => 'YouTube', 'url' => 'https://...'],
    ],
]
```

### 이메일 HTML 렌더링 순서

1. **사이트 제목** (`get_bloginfo('name')`) — WordPress 일반 설정의 사이트 이름
2. **소셜 아이콘** — preset(Facebook·X·Instagram·LinkedIn): `<img>` 태그, PNG 파일 사용
3. **커스텀 소셜** — `icon` 필드(이미지 URL)가 있으면 `<img>`, 없으면 이름 첫 두 글자 원형 버튼 fallback
4. **웹사이트 링크** — inline style 텍스트
5. **사업자 정보** — inline style 텍스트
6. **수신 거부 링크** — `$unsubscribe_url` 전달 시 출력

설정된 항목이 없으면 빈 문자열 반환 (푸터 미출력).

### 소셜 아이콘 렌더링 방식

**프리셋 소셜 (Facebook·X·Instagram·LinkedIn)**  
- `public/assets/icon/` 경로의 PNG 파일을 `<img>` 태그로 출력
- 브랜드 색상 원형 배경에 흰색 아이콘 PNG (8px padding → 32px 버튼)
- 이메일 클라이언트에서 SVG/FA 의존 없이 안정적으로 렌더링
- 새 preset 아이콘 추가 시: PNG를 `public/assets/icon/`에 추가 + `$presets` 배열에 항목 추가

**커스텀 소셜**  
- 어드민 "④ 커스텀 소셜 링크" 섹션에서 WP 미디어 라이브러리로 이미지 선택
- `icon` 필드에 이미지 URL 저장 (`esc_url_raw()` sanitize)
- 이미지 미설정 시 이름 첫 두 글자 원형 버튼 fallback
- Font Awesome 클래스 방식은 더 이상 사용하지 않음

---

## DB 버전 관리

`sw-bulk-email.php`의 `sw_bulk_email_check_db()` 함수가 `plugins_loaded` 훅에서  
`get_option('sw_bulk_email_db_version') !== SW_BULK_EMAIL_VERSION` 조건 시 실행됨.

현재 버전: `1.0.3`

### 마이그레이션 흐름

1. `SW_DB::create_table()` / `create_templates_table()` / `create_archive_table()` 호출 (`dbDelta()` 사용)
2. **컬럼별 명시적 ALTER TABLE** — `dbDelta()`가 기존 테이블에 컬럼을 추가하지 못하는 경우가 있으므로, 버전별 신규 컬럼은 `SHOW COLUMNS ... LIKE '컬럼명'`으로 존재 여부를 확인 후 없으면 직접 `ALTER TABLE`로 추가
3. `update_option('sw_bulk_email_db_version', SW_BULK_EMAIL_VERSION)` 호출 → 이후 재실행 방지

> 새 컬럼 추가 시: ① 버전 번호 올리기 ② `create_archive_table()` SQL 업데이트 ③ `sw_bulk_email_check_db()`에 해당 컬럼 ALTER TABLE 블록 추가.  
> `dbDelta()` 단독으로는 신뢰하지 말 것 — 기존 테이블 컬럼 추가 시 조용히 실패하는 사례가 있음.  
> `sw_bulk_email_check_db()`는 버전 게이트 **바깥**에서 `status` 컬럼 존재 여부를 항상 확인한다. 이는 버전이 이미 `1.0.3`으로 기록됐지만 컬럼이 누락된 경우를 복구하기 위한 안전장치.  
> `archive_save()`도 INSERT 실패 시 `status` 컬럼 존재를 확인하고 없으면 직접 추가한 뒤 재시도하는 자가 복구 로직을 내장한다.

### 버전별 마이그레이션 내역

| 버전 | 변경 내용 |
|---|---|
| `1.0.2` | 초기 스키마 (subscribers, templates, archive) |
| `1.0.3` | `wp_sw_email_archive`에 `status` 컬럼 추가 (`'draft'`\|`'sent'`) |

---

## 아카이브 DB 스키마 (`wp_sw_email_archive`)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| id | BIGINT UNSIGNED AI | PK |
| subject | TEXT | 메일 제목 |
| body | LONGTEXT | 메일 본문 (HTML) |
| mail_type | VARCHAR(20) | 'subscriber' / 'ad' / 'system' |
| status | VARCHAR(20) | 'draft' (임시저장) / 'sent' (발송완료) |
| sent_count | INT UNSIGNED | 성공 발송 수 |
| failed_count | INT UNSIGNED | 실패 수 |
| is_public | TINYINT(1) | 공개 숏코드 노출 여부 |
| created_at | DATETIME | 최초 저장일 |
| updated_at | DATETIME | 마지막 수정일 |

> `status` 컬럼은 버전 `1.0.3`에서 추가됨. 기존 행은 DEFAULT `'sent'`로 처리됨.

### 아카이브 관련 `SW_DB` 메서드

```php
SW_DB::create_archive_table(): void
SW_DB::archive_save(string $subject, string $body, string $mail_type, string $status = 'sent'): int
SW_DB::archive_update_stats(int $id, int $sent, int $failed): bool
SW_DB::archive_update_content(int $id, string $subject, string $body): bool
SW_DB::archive_update_status(int $id, string $status): bool
SW_DB::archive_get(int $id): ?array
SW_DB::archive_list(int $limit, int $offset): array   // status 컬럼 포함
SW_DB::archive_count(): int
SW_DB::archive_delete(int $id): bool
SW_DB::archive_list_public(int $limit, int $offset): array
SW_DB::archive_count_public(): int
SW_DB::archive_toggle_public(int $id, bool $public): bool
```

### 아카이브 저장·발송 흐름 (JS)

**임시저장 흐름**
1. "💾 임시저장" 버튼 클릭 → `saveDraft(subject, body, mailType, $btn, statusSelector)` 호출
2. `sw_archive_save` AJAX (`status='draft'`) → archive_id 반환
3. 성공 notice에 발송 내역 링크 표시

**발송 흐름 (Compose & Send)**
1. 발송 버튼 클릭 → `swArchiveSave(subject, body, mailType, callback, status='sent')` 호출
2. `sw_archive_save` AJAX → archive_id 반환
3. callback 안에서 배치 발송 시작
4. 배치 완료 → `swArchiveFinish(archiveId, sent, failed)` 호출
5. `sw_archive_finish` AJAX → sent_count / failed_count 업데이트

**발송 내역 편집에서 발송 흐름**
- **임시저장 항목**: `sw_archive_update_status` AJAX로 `status='sent'` 변경 → 동일 archive_id로 배치 발송
- **발송완료 항목(재발송)**: `sw_archive_save`로 새 항목 생성 → 새 archive_id로 배치 발송

---

## 어드민 메뉴 구조

| 메뉴 | slug | 렌더 메서드 |
|---|---|---|
| SW Bulk Email (최상위) | `sw-bulk-email` | `render_compose_page` |
| Compose & Send | `sw-bulk-email` | `render_compose_page` |
| Subscribers | `sw-bulk-email-subscribers` | `render_subscribers_page` |
| 발송 내역 | `sw-bulk-email-archive` | `render_archive_page` → `SW_Archive_Page::render()` |
| Embed Form | `sw-bulk-email-embed` | `render_embed_page` |
| 메일 푸터 설정 | `sw-bulk-email-footer` | `render_footer_settings_page` |
| 사용 안내 | `sw-bulk-email-manual` | `render_manual_page` — `[sw_optin_form]` · `[sw_email_archive]` 숏코드 안내, WP-Members 연동 안내 |

---

## AJAX 액션 목록

| action | 메서드 | 권한 | 설명 |
|---|---|---|---|
| `sw_send_batch` | `ajax_send_batch` | admin | 구독자 메일 배치 발송 |
| `sw_send_ad_batch` | `ajax_send_ad_batch` | admin | 광고 메일 배치 발송 |
| `sw_send_system_batch` | `ajax_send_system_batch` | admin | 전체 발송 |
| `sw_test_send` | `ajax_test_send` | admin | 관리자 테스트 발송 |
| `sw_get_subscriber_count` | `ajax_get_count` | admin | 구독자 수 반환 |
| `sw_load_template` | `ajax_load_template` | admin | 템플릿 불러오기 |
| `sw_save_template` | `ajax_save_template` | admin | 템플릿 저장 |
| `sw_delete_template` | `ajax_delete_template` | admin | 템플릿 삭제 |
| `sw_archive_save` | `ajax_archive_save` | admin | 아카이브 항목 생성 (`status` 파라미터로 draft/sent 지정) |
| `sw_archive_finish` | `ajax_archive_finish` | admin | 발송 통계 업데이트 |
| `sw_archive_update` | `ajax_archive_update` | admin | 아카이브 내용 수정 |
| `sw_archive_delete` | `ajax_archive_delete` | admin | 아카이브 삭제 |
| `sw_archive_toggle_public` | `ajax_archive_toggle_public` | admin | 공개/비공개 토글 |
| `sw_archive_update_status` | `ajax_archive_update_status` | admin | draft → sent 상태 변경 |
| `sw_delete_subscriber` | `ajax_delete_subscriber` | admin | 구독자 삭제 (AJAX) |
| `sw_get_archive_body` | `ajax_get_archive_body` | **nopriv** | 공개 body 반환 (숏코드 모달용) |
| `sw_embed_subscribe` | `ajax_embed_subscribe` (SW_Optin) | **nopriv** | 외부 임베드 폼 구독 처리 |
| `sw_embed_regen_token` | `ajax_embed_regen_token` | admin | 임베드 토큰 재생성 |

모든 admin AJAX는 nonce `sw_send_batch` + `manage_options` 권한 검사.  
`sw_get_archive_body` · `sw_embed_subscribe`는 인증 불필요.  
`sw_embed_subscribe`는 토큰 검증으로 무단 요청 차단, CORS 헤더 자동 삽입.

---

## 숏코드

### `[sw_optin_form]` — 구독 폼

| 속성 | 기본값 | 설명 |
|---|---|---|
| `button` | `"Subscribe"` | 구독 버튼 텍스트 |

```
[sw_optin_form]
[sw_optin_form button="뉴스레터 구독하기"]
```

### `[sw_email_archive]` — 발송 메일 목록

`is_public=1`로 설정된 메일만 노출. 제목 클릭 시 본문 모달 표시.  
공개 설정: 발송 내역 페이지에서 공개여부 버튼으로 전환.

| 속성 | 기본값 | 설명 |
|---|---|---|
| `per_page` | `10` | 페이지당 표시 수 |
| `type` | (전체) | `subscriber` / `ad` / `system` 중 하나로 유형 필터 |

```
[sw_email_archive]
[sw_email_archive per_page="5"]
[sw_email_archive type="subscriber" per_page="10"]
```

임베드 URL: `/sw-embed-form` (하위 호환용 iframe 라우트, 유지 중)

---

## 외부 임베드 폼 (HTML+CSS 방식)

**관리자 메뉴**: SW Bulk Email → Embed Form

### 개요

iframe 없이 순수 HTML+CSS+JS 스니펫을 생성해 어느 사이트에나 붙여넣을 수 있는 구독 폼.  
관리자 페이지에서 스타일을 실시간으로 설정하면 코드가 자동 생성된다.

### 커스터마이즈 가능 항목

| 항목 | 설명 |
|---|---|
| 주 색상 (버튼) | `--sw-embed-primary` CSS 변수 |
| 테두리 색상 | `--sw-embed-border` |
| 입력창 배경색 | `--sw-embed-bg` |
| 모서리 둥글기 | `--sw-embed-radius` (0–24px) |
| 폼 제목 / 설명 문구 | 선택 표시 |
| 레이블 / 플레이스홀더 / 버튼 텍스트 | 자유 편집 |
| 광고 수신 동의 체크박스 | 표시/숨김 + 문구 편집 |

### 생성 스니펫 구조

```html
<style>
  :root { --sw-embed-primary: …; … }
  .sw-embed-wrap { … }   /* 커스터마이즈 가능 */
  …
</style>

<div class="sw-embed-wrap">
  <form class="sw-embed-form" id="sw-embed-form"> … </form>
</div>

<script>
  /* fetch로 sw_embed_subscribe AJAX 호출, 결과를 인라인 메시지로 표시 */
</script>
```

### 보안 토큰 (`sw_bulk_email_embed_token`)

- 플러그인 활성화 시 `bin2hex(random_bytes(16))`으로 자동 생성, `wp_options`에 저장
- 스니펫 JS에 하드코딩되어 `sw_embed_subscribe` 엔드포인트 인증에 사용
- 토큰 유출 시 관리자 페이지에서 재생성 가능 (재생성 후 기존 코드 교체 필요)

### `ajax_embed_subscribe` 처리 흐름 (`SW_Optin`)

1. CORS 헤더 삽입 (`Access-Control-Allow-Origin: *`)
2. `$_POST['token']` vs `get_option('sw_bulk_email_embed_token')` 검증
3. 이메일 유효성 검사 → 중복 확인
4. `SW_DB::add_subscriber()` → `send_confirmation_email()` (더블 옵트인)
5. JSON 성공/실패 응답

---

## 알려진 주의사항

1. **WP-Members 관리자 UI에서 `sw_subscribe_newsletter` 필드를 직접 추가하면 안 됨.**  
   코드(`wpmem_fields` 필터)에서 이미 주입하므로, UI에서 추가 시 중복 발생.

2. **코스모스팜 스킨은 WP-Members 필드를 직접 렌더링하지 않고 자체 스킨 템플릿 사용.**  
   `wpmem_register_form_rows` 필터로 렌더링된 HTML 행을 가져와 재가공함.

3. **`wpmem_post_register_data` 훅은 `$user_id`가 아닌 `array $post_data`를 전달.**  
   `$post_data['ID']`에서 user_id를 추출해야 함.

4. **시스템 메일 전체 발송 시 수신자 목록을 transient에 캐시.**  
   offset=0일 때 새로 빌드, 이후 배치는 캐시 사용. 발송 완료 시 자동 삭제.

5. **광고 메일 발송 시 제목에 `[광고]` 자동 추가** (정보통신망법 준수).

6. **임베드 토큰은 스니펫 코드에 평문으로 포함된다.**  
   토큰이 유출되면 외부에서 스팸 구독이 가능하므로, 악용 의심 시 즉시 재생성할 것.  
   재생성 후에는 배포된 모든 스니펫 코드를 새 코드로 교체해야 한다.

7. **`/sw-embed-form` 라우트(iframe용)는 하위 호환을 위해 유지 중.**  
   신규 구현은 HTML+CSS 스니펫 방식을 사용하고, 이 라우트에 의존하는 기능은 새로 개발하지 않는다.

8. **Subscribers 페이지의 구독자 삭제는 AJAX(`sw_delete_subscriber`)로 처리한다.**  
   이전에 GET + `check_admin_referer()` 방식을 사용했으나, referer 검사 실패로 "링크가 만료되었습니다" 오류가 발생해 AJAX 방식으로 교체됨.  
   `sw_send_batch` nonce + `manage_options` 권한 검사. 성공 시 해당 행 fade-out, notice 표시. 페이지 새로고침 없음.  
   `SW_DB::delete_by_id()`를 사용하며, DB에서 영구 삭제됨 (복구 불가).

   **삭제 JS는 `sw-admin.js`가 아닌 `render_subscribers_page()` 안에 인라인으로 직접 삽입한다.**  
   `sw-admin.js`는 Compose & Send 페이지 전용 `swBulkEmail` 객체를 전제하므로, 구독자 페이지에서  
   `swBulkEmail`이 미정의 상태일 때 클릭 시 `ReferenceError`가 발생해 조용히 실패한다.  
   인라인 스크립트는 `wp_json_encode(admin_url('admin-ajax.php'))`와 `wp_create_nonce('sw_send_batch')`를  
   PHP에서 직접 출력하므로 `swBulkEmail` 의존성이 없다.

9. **커스텀 소셜 아이콘의 `icon` 필드는 FA 클래스가 아닌 이미지 URL을 저장한다.**  
   `sanitize_custom_social()`에서 `esc_url_raw()`로 sanitize.  
   어드민에서 WP 미디어 라이브러리로 이미지를 선택하면 URL이 hidden input에 저장됨.

10. **소셜 아이콘은 SVG·Font Awesome 대신 PNG `<img>` 태그를 사용한다.**  
    Gmail 등 이메일 클라이언트는 SVG `style` 속성 및 외부 폰트를 제거하므로 FA·SVG 방식은 동작하지 않음.  
    `public/assets/icon/`의 PNG 파일(흰색 아이콘, 투명 배경)을 브랜드 색상 원형 `<a>` 안에 `<img>`로 출력.  
    Font Awesome CDN 로드도 제거됨 (`wp_enqueue_media()`로 교체).

11. **어드민 푸터 미리보기의 `wp_kses` 허용 목록에 `img` 태그가 포함되어야 한다.**  
    `section_preview()`에서 `SW_Email_Footer::get_html()`의 `<img>` 태그가 허용 목록에 없으면 제거됨.  
    현재 허용: `img → [src, width, height, alt, style, border]`.

12. **임시저장 항목을 발송 내역 편집에서 발송하면 기존 항목이 `sent`로 갱신된다 (새 항목 생성 없음).**  
    이미 발송된 항목을 재발송하면 새 아카이브 항목이 생성된다 (기존 동작 유지).  
    `data-status` 속성으로 두 경우를 구분하며, JS `startArchiveSend()` 함수가 분기 처리.

13. **임시저장 항목은 공개/비공개 토글이 비활성화된다.**  
    발송 내역 목록에서 `status='draft'`인 항목은 공개여부 버튼이 표시되지 않음.  
    `is_public` 플래그는 발송 완료(`status='sent'`) 후에 의미를 가짐.
