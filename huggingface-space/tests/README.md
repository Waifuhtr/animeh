# Space testleri

`space-e2e.mjs` Space'in **gerçek** arayüzünü Chromium'da sürüyor. Android SDK
gerektirmiyor: gradle yerine, gerçek gradle gibi davranan bir stub konuyor —
ilerleme basıyor, biraz sürüyor, ve Gradle'ın koyacağı yere bir APK bırakıyor.

Yani sınanan şey derleyicinin kendisi değil, etrafındaki her şey: derlemenin
başlaması, logun **akması**, başarıda APK'nın indirilebilir olması, hatanın
hata olarak raporlanması, iptalin gerçekten durdurması.

```bash
# 1. Space'i bir kopyaya aç
mkdir -p /tmp/spacetest && cd /tmp/spacetest
unzip -q dist/animeh-apk-builder-space.zip

# 2. gradle yerine stub koy (aşağıdaki betik)
#    STUB_FAIL=1 ile hata yolunu sürer.

# 3. Sunucuyu başlat
pip install -r requirements.txt
PORT=7860 python3 app.py &

# 4. Testleri koştur
node tests/space-e2e.mjs
```

Stub `gradlew`:

```bash
#!/usr/bin/env bash
set -u
echo "Starting a Gradle Daemon"
echo "> Task :app:compileDebugKotlin"
echo "w: warning: unused variable"
for i in $(seq 1 12); do echo "> Task :app:step$i"; sleep 0.5; done
if [ "${STUB_FAIL:-0}" = "1" ]; then
  echo "e: error: unresolved reference: nope"
  echo "BUILD FAILED in 8s"; exit 1
fi
mkdir -p app/build/outputs/apk/debug
head -c 1200000 /dev/urandom > app/build/outputs/apk/debug/app-debug.apk
echo "BUILD SUCCESSFUL in 8s"
```
