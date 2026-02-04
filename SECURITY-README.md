# Security Implementation Guide

## ✅ Already Implemented (Client-Side)

### 1. Meta Tags de Seguridad
Ambas páginas (`t-9k3jf2a.html` y `i-7m2kd8x.html`) incluyen:
- ✅ Robots meta tags (noindex, nofollow)
- ✅ X-Frame-Options (prevent clickjacking)
- ✅ X-Content-Type-Options (prevent MIME sniffing)
- ✅ Referrer Policy (no-referrer)
- ✅ Content Security Policy

### 2. Auto-Limpieza de Datos
- ✅ Las URLs guardadas se eliminan automáticamente después de 30 días
- ✅ Se ejecuta una vez al día automáticamente
- ✅ Puedes cambiar el periodo en `MAX_STORAGE_DAYS`

### 3. Archivos Renombrados
- ✅ `index.html` → `t-9k3jf2a.html` (Tracker)
- ✅ `impression.html` → `i-7m2kd8x.html` (Impression)
- Los archivos originales se mantienen por compatibilidad

### 4. Robots.txt
- ✅ Bloquea todos los bots de indexación

## 🔧 Configuración del Servidor (Requerida)

### Paso 1: Activar .htaccess

El archivo `.htaccess` ya está creado. Asegúrate de que Apache esté configurado para permitir .htaccess:

```apache
# En httpd.conf o virtual host config
<Directory "/ruta/a/tu/directorio">
    AllowOverride All
</Directory>
```

### Paso 2: Configurar Autenticación HTTP (ALTAMENTE RECOMENDADO)

1. **Crear archivo de contraseñas:**

```bash
# En tu servidor, ejecuta:
cd /Users/Enrique/workspace/tracking2/tracking2
htpasswd -c .htpasswd tu_usuario
# Te pedirá que ingreses una contraseña
```

2. **Descomentar en .htaccess:**

Edita `.htaccess` y descomenta estas líneas (líneas 17-21):

```apache
AuthType Basic
AuthName "Restricted Access - Tracking Tools"
AuthUserFile /Users/Enrique/workspace/tracking2/tracking2/.htpasswd
Require valid-user
```

3. **Cambiar la ruta:**
Reemplaza `/Users/Enrique/workspace/tracking2/tracking2/.htpasswd` con la ruta ABSOLUTA en tu servidor.

### Paso 3: Configurar IP Whitelist (Opcional pero Recomendado)

Si solo accedes desde IPs conocidas, descomenta en `.htaccess` (líneas 27-31):

```apache
Order Deny,Allow
Deny from all
Allow from YOUR.IP.ADDRESS
Allow from ANOTHER.IP.ADDRESS
```

Reemplaza `YOUR.IP.ADDRESS` con tu IP real. Puedes encontrarla en: https://whatismyipaddress.com

### Paso 4: Forzar HTTPS

El `.htaccess` ya incluye redirección automática a HTTPS. Solo asegúrate de tener un certificado SSL instalado.

Para obtener un certificado SSL gratuito:
- **Let's Encrypt:** https://letsencrypt.org/
- **Certbot:** https://certbot.eff.org/

## 📁 Estructura de Archivos

```
tracking2/
├── t-9k3jf2a.html      # Tracker principal (antes index.html)
├── i-7m2kd8x.html      # Impression page (antes impression.html)
├── index.html          # Original (puedes eliminarlo si usas el renombrado)
├── impression.html     # Original (puedes eliminarlo si usas el renombrado)
├── robots.txt          # Bloquea bots
├── .htaccess           # Configuración de seguridad
├── .htpasswd           # Contraseñas (crear con htpasswd)
└── SECURITY-README.md  # Este archivo
```

## 🔒 Niveles de Seguridad

### Nivel 1 - Básico (Ya implementado)
- ✅ Meta tags de seguridad
- ✅ Robots.txt
- ✅ Nombres de archivos ofuscados
- ✅ Auto-limpieza de datos

### Nivel 2 - Recomendado (Requiere servidor)
- ⚠️ HTTPS forzado (.htaccess ya configurado)
- ⚠️ Headers de seguridad (.htaccess ya configurado)
- ⚠️ Autenticación HTTP básica (necesitas configurar)

### Nivel 3 - Máxima Seguridad (Requiere servidor)
- ⚠️ IP Whitelist (necesitas configurar)
- ⚠️ Rate limiting (requiere mod_evasive)
- ⚠️ Certificado SSL (Let's Encrypt)

## 🚀 Acceso a las Páginas

### Con archivos renombrados:
- Tracker: `https://tudominio.com/t-9k3jf2a.html`
- Impression: `https://tudominio.com/i-7m2kd8x.html`

### Con autenticación HTTP configurada:
Al acceder, el navegador pedirá usuario y contraseña.

## ⚡ Comandos Útiles

### Ver logs de Apache (errores):
```bash
tail -f /var/log/apache2/error.log
```

### Verificar configuración de Apache:
```bash
apache2ctl configtest
```

### Reiniciar Apache después de cambios:
```bash
sudo systemctl restart apache2
```

### Verificar si mod_headers está habilitado:
```bash
apache2ctl -M | grep headers
```

Si no está habilitado:
```bash
sudo a2enmod headers
sudo systemctl restart apache2
```

## 🛡️ Monitoreo

### Logs importantes a revisar:
1. `/var/log/apache2/access.log` - Accesos
2. `/var/log/apache2/error.log` - Errores
3. Consola del navegador - JavaScript errors

### Qué buscar:
- Intentos de acceso sospechosos
- IPs desconocidas
- Intentos de inyección SQL/XSS
- Patrones de escaneo de bots

## 📞 Soporte

Si necesitas ayuda adicional:
1. Revisa logs de Apache
2. Verifica que mod_rewrite y mod_headers estén habilitados
3. Asegúrate de que .htaccess sea leído (AllowOverride All)

## ⚠️ Importante

- **NO compartas** las URLs renombradas públicamente
- **Cambia las contraseñas** regularmente
- **Revisa los logs** periódicamente
- **Actualiza** los archivos .htpasswd si cambias de IP
- **Usa HTTPS** siempre que sea posible

---

**Última actualización:** 2026-02-04
**Nivel de seguridad actual:** Nivel 1 (Básico)
**Nivel recomendado:** Nivel 2 (con autenticación HTTP)
