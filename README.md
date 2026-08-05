# AMPER Live Assisted Sales - wtyczka WordPress / WooCommerce

AMPER Live Assisted Sales łączy Twój sklep WooCommerce z platformą [AMPER](https://live-assisted-sales.com), dzięki której możesz obserwować odwiedzających w czasie rzeczywistym, rozmawiać z nimi na czacie i zwiększać sprzedaż.

- **widzisz w czasie rzeczywistym, ilu odwiedzających jest w sklepie** - co oglądają, czego szukają, co mają w koszyku i jakie zamówienia złożyli,
- **wiesz, komu pomóc najpierw** - każda wizyta otrzymuje ocenę prawdopodobieństwa zakupu (niska / średnia / wysoka),
- **rozmawiasz z klientami przez czat** - dymek czatu w sklepie, podpowiedzi AI, polecanie produktów oraz dodawanie ich do koszyka bez opuszczania rozmowy,
- **dbasz o zgodę odwiedzających z UE** - dzięki wbudowanemu banerowi zgody; bez zgody dane o zachowaniu ani dane osobowe nie są wysyłane do platformy AMPER.

Do korzystania z wtyczki wymagane jest konto w usłudze AMPER Live Assisted Sales - po połączeniu sklepu wszystkie funkcje są dostępne od razu. Konto jest bezpłatne, a przez pierwsze 7 dni możesz korzystać z okresu próbnego.

## Czego potrzebujesz

- WordPress 6.2 lub nowszy, PHP 8.0 lub nowszy,
- włączona wtyczka **WooCommerce** (bez niej ta wtyczka się nie aktywuje),
- dostęp do internetu umożliwiający komunikację z platformą AMPER,
- najnowsza wersja wtyczki - [amper-live-assisted-sales.zip](https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress/releases/download/latest/amper-live-assisted-sales.zip).

## Instalacja krok po kroku

1. Pobierz plik wtyczki: [amper-live-assisted-sales.zip](https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress/releases/download/latest/amper-live-assisted-sales.zip).
2. Zaloguj się do panelu swojego sklepu (`twojsklep.pl/wp-admin`).
3. Wejdź w **Wtyczki → Dodaj nową wtyczkę → Wyślij wtyczkę na serwer**.
4. Wybierz plik `amper-live-assisted-sales.zip`, kliknij **Zainstaluj**, a potem **Włącz wtyczkę**.
5. Po aktywacji kliknij odnośnik **Ustawienia** przy wtyczce lub przejdź do **WooCommerce → Live Assisted Sales**.
6. Kliknij **Połącz z AMPER LAS**. Przeniesiemy Cię na live-assisted-sales.com, gdzie logujesz się (lub zakładasz konto) i potwierdzasz połączenie jednym kliknięciem. Klucz API zostanie zapisany automatycznie - nie musisz niczego kopiować ani wklejać.
7. Gotowe. Dymek czatu pojawia się w sklepie, a w konsoli na live-assisted-sales.com widzisz ruch w czasie rzeczywistym.

Możesz również połączyć sklep ręcznie: na stronie ustawień wklej **Klucz API sklepu** ze strony Twojego sklepu w konsoli, zapisz i kliknij **Przetestuj połączenie**.

## Częste pytania

**Czy wtyczka spowolni mój sklep?**
Nie. Dane są wysyłane asynchronicznie i nie blokują ładowania strony, a zdarzenia sprzedażowe (koszyk, zamówienie) trafiają do lokalnej kolejki z ponawianiem - chwilowe problemy z siecią nie powodują utraty zdarzeń.

**Czy muszę pilnować aktualizacji?**
Nie. Nowe wersje będą instalowane automatycznie, tak jak w przypadku innych wtyczek WordPressa.

**Jak wstrzymać wysyłanie danych?**
W **WooCommerce → Live Assisted Sales** odznacz **Integracja włączona**. Wyłączenie wtyczki też zatrzymuje wszystko, a jej odinstalowanie usuwa ustawienia i kolejkę zdarzeń z lokalnej bazy danych WordPressa.

**Co z danymi moich klientów?**
Odwiedzający z UE najpierw widzą baner zgody - bez zgody dane o zachowaniu ani dane osobowe nie są wysyłane do platformy AMPER. Szczegółowe informacje znajdziesz w naszym [Regulaminie](https://live-assisted-sales.com/terms/) oraz [Polityce prywatności](https://live-assisted-sales.com/privacy/).

## Pomoc

Coś nie działa albo masz pytanie? Napisz do nas - dane kontaktowe znajdziesz na [live-assisted-sales.com](https://live-assisted-sales.com).

---

Dokumentacja techniczna (środowisko deweloperskie, testy, wydawanie wersji, architektura integracji): [DEVELOPMENT.md](DEVELOPMENT.md).
