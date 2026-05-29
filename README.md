# SW Bulk Email & Opt-in 📧

![Version](https://img.shields.io/badge/version-1.0.3-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-7.0+-207196?logo=wordpress)
![License](https://img.shields.io/badge/license-GPLv2-green.svg)
![Made with Claude](https://img.shields.io/badge/Made_with-Claude_AI-8a2be2.svg)

**SW Bulk Email**은 워드프레스 내에서 직접 뉴스레터 구독자를 모집하고 대량 메일을 발송할 수 있는 가볍고 강력한 원앱(One-App) 뉴스레터 솔루션입니다. 

비싼 외부 뉴스레터 서비스(Mailchimp, Stibee 등)에 의존하거나 소중한 고객 DB를 외부로 반출할 필요 없이, 내 워드프레스 서버에서 모든 것을 통제하세요.

## ✨ 주요 기능
* **완벽한 데이터 주권:** 모든 구독자 정보와 발송 내역이 내 워드프레스 DB에만 안전하게 저장됩니다.
* **타겟팅 대량 발송:** * 일반 구독자 메일 (더블 옵트인 완료자)
  * 광고성 메일 (광고 수신 동의자 전용, `[광고]` 헤더 자동 추가)
  * 시스템 공지 (동의 여부 무관, 전체 가입자 대상)
* **임베드 폼 생성기:** Newspaper 테마나 벤토(Bento) 레이아웃에 완벽하게 밀착되는 디자인 커스텀 구독 폼 코드를 제공합니다.
* **뉴스레터 아카이브:** 과거에 발송한 뉴스레터를 웹사이트에 공개/비공개 처리하고 숏코드로 예쁘게 나열할 수 있습니다.
* **WP-Members 연동:** WP-Members 플러그인 사용 시 회원가입 폼에 뉴스레터/광고 수신 동의 체크박스가 자동으로 삽입됩니다.

## ⚠️ 요구 사항 및 필수 환경
* **PHP:** 8.0 이상 권장
* **WordPress:** 7.0 이상 권장
* **필수 플러그인:** [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/) (대량 메일 발송 시 서버 부하 방지 및 스팸 처리 방지를 위해 필수적으로 연동해야 합니다.)
* **테스트 완료 SMTP 서버:** **Brevo** (구 Sendinblue) 환경에서 안정성 테스트를 완료했습니다.

## 🚀 설치 방법
1. 본 리포지토리 우측 상단의 초록색 **[<> Code]** 버튼을 누르고 **Download ZIP**을 클릭하여 파일을 다운로드합니다.
2. 워드프레스 관리자 페이지 > **플러그인 > 새로 추가 > 플러그인 업로드** 메뉴로 이동합니다.
3. 다운로드한 `.zip` 파일을 업로드하고 설치 후 활성화합니다.
4. 관리자 메뉴 좌측의 **[SW Bulk Email]** 탭에서 설정을 시작하세요.

## 💻 사용 방법 (숏코드)

**1. 구독 폼 삽입**
원하는 글, 페이지, 위젯에 아래 숏코드를 삽입하세요.
```text
[sw_optin_form button="구독하기"]
```

**2. 공개 발송 내역(아카이브) 삽입**
관리자가 '공개'로 설정한 뉴스레터 목록을 표시합니다.
```text
[sw_email_archive per_page="10" type="subscriber"]
```
(type 옵션 ```subscriber```, ```ad```, ```system``` / 비워두면 전체 표시)

## 🗓️ 앞으로 개발 예정 (Roadmap)
본 플러그인은 지속적으로 고도화될 예정입니다. 향후 다음 기능이 추가됩니다.
* [] 다국어 기능 지원 (Multilingual Support)
* [] 발송 통계 기능 (메일 오픈율 및 링크 클릭률 추적)

# 개발자 코멘트 및 지원 정책
이 플러그인은 개인 및 자사 운영 목적으로 기획되었으며, **Claude AI와의 페어 프로그래밍(Pair Programming)을 통해 100% 개발되었습니다.** 워드프레스 생태계를 위해 무료로 배포(GPL v2)하는 프로젝트입니다.


**🤖 AI 친화적 커스텀 (Claude Code 지원)**

본 리포지토리에는 프로젝트의 맥락과 개발 규칙이 담긴 `CLAUDE.md` 파일이 포함되어 있습니다. 코드를 직접 수정하시거나 새로운 기능을 추가하실 때, **Claude Code (또는 Claude AI)**에 이 파일을 읽히시면 AI가 프로젝트 구조를 즉시 파악하여 훨씬 빠르고 정확하게 커스텀 작업을 도와줄 것입니다.

>**Notice:** 다양한 테마 및 서버 환경에 따른 개별적인 기술 지원이나 충돌 해결(CS)은 제공하지 않습니다. 본인의 서버 환경에서 오류가 발생하거나 디자인이 맞지 않을 경우, 코드를 직접 수정(커스텀)하여 자유롭게 사용하시기 바랍니다. Pull Request는 언제나 환영합니다!

# 📜 라이선스

이 플러그인은 GPL General Public License v2.0을 따릅니다.

