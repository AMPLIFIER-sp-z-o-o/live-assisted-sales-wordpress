# AMPER Live Assisted Sales - wtyczka WordPress / WooCommerce

Wtyczka łączy Twój sklep WooCommerce z platformą [AMPER Live Assisted Sales](https://live-assisted-sales.com). Po jej włączeniu:

- **widzisz na żywo, kto jest w sklepie** - co ogląda, czego szuka, co ma w koszyku i co kupił,
- **wiesz, komu pomóc najpierw** - każda wizyta dostaje ocenę zamiaru zakupu (niska / średnia / wysoka),
- **rozmawiasz z klientami przez czat** - dymek czatu w sklepie, podpowiedzi AI, polecanie produktów i dodawanie ich do koszyka prosto z rozmowy,
- **RODO masz z głowy** - wbudowany baner zgody dla odwiedzających z UE; bez zgody żadne dane o zachowaniu ani dane osobowe nie są wysyłane.

Wtyczka sama w sobie niczego nie robi - potrzebujesz konta na [live-assisted-sales.com](https://live-assisted-sales.com). Konto zakładasz za darmo, pierwsze 7 dni to okres próbny.

## Czego potrzebujesz

- WordPress 6.2 lub nowszy, PHP 8.0 lub nowszy,
- włączona wtyczka **WooCommerce** (bez niej ta wtyczka się nie aktywuje),
- plik wtyczki `amper-live-assisted-sales.zip` - otrzymasz go od nas.

## Instalacja krok po kroku

1. Zaloguj się do panelu swojego sklepu (`twojsklep.pl/wp-admin`).
2. Wejdź w **Wtyczki → Dodaj nową wtyczkę → Wyślij wtyczkę na serwer**.
3. Wskaż plik `amper-live-assisted-sales.zip`, kliknij **Zainstaluj**, a potem **Włącz wtyczkę**.
4. Po włączeniu zobaczysz komunikat z linkiem do ustawień - kliknij go (albo wejdź w **WooCommerce → Live Assisted Sales**).
5. Kliknij **Połącz z AMPER LAS**. Przeniesiemy Cię na live-assisted-sales.com, gdzie logujesz się (lub zakładasz konto) i potwierdzasz połączenie jednym kliknięciem. Klucz sklepu zapisze się sam - niczego nie kopiujesz.
6. Gotowe. Dymek czatu pojawia się w sklepie, a w konsoli na live-assisted-sales.com widzisz ruch na żywo.

Wolisz zrobić to ręcznie? Na stronie ustawień wklej **Klucz API sklepu** ze strony Twojego sklepu w konsoli, zapisz i kliknij **Testuj połączenie**.

## Częste pytania

**Czy wtyczka spowolni mój sklep?**
Nie. Dane wysyłane są w tle, poza ładowaniem strony, a zdarzenia sprzedażowe (koszyk, zamówienie) trafiają do lokalnej kolejki z ponawianiem - chwilowy problem z siecią niczego nie gubi.

**Czy muszę pilnować aktualizacji?**
Nie. Wtyczka aktualizuje się sama, tak jak wtyczki z katalogu WordPressa.

**Jak wstrzymać wysyłanie danych?**
W **WooCommerce → Live Assisted Sales** odznacz **Integracja włączona**. Wyłączenie wtyczki też zatrzymuje wszystko, a jej odinstalowanie usuwa ustawienia i kolejkę zdarzeń.

**Co z danymi moich klientów?**
Odwiedzający z UE najpierw widzą baner zgody - bez zgody nie zbieramy danych o zachowaniu ani danych osobowych. Szczegóły: [regulamin](https://live-assisted-sales.com/terms/) i [polityka prywatności](https://live-assisted-sales.com/privacy/).

## Pomoc

Coś nie działa albo masz pytanie? Napisz do nas - dane kontaktowe znajdziesz na [live-assisted-sales.com](https://live-assisted-sales.com).

---

Dokumentacja techniczna (środowisko deweloperskie, testy, wydawanie wersji, architektura integracji): [DEVELOPMENT.md](DEVELOPMENT.md).
