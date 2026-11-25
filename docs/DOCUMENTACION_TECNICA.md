# Documentación técnica · FEDER Web

## 1. Propósito y alcance

FEDER Web es el Proyecto 2 de INF513 del grupo 29. Convierte el sistema de comercialización agrícola del Proyecto 1 en una aplicación web para visitantes, clientes, productores, personal de FEDER y administradores.

La aplicación cubre catálogo, carrito, pedidos, pagos, cuotas, entregas, facturación, devoluciones, inventario por lote, compras a productores, producciones, reportes, notificaciones, administración de usuarios, permisos y paneles de indicadores.

## 2. Arquitectura

La solución usa tres capas.

1. **Presentación:** React 19 y Vite en `frontend/`. Las llamadas HTTP se concentran en `src/api`. La sesión y preferencias en contextos y hooks. Las páginas y componentes solo presentan e interactúan.
2. **Negocio:** Laravel 12 en `backend/`. El flujo es ruta → middleware → FormRequest → Controller → Service → Repository/Model.
3. **Datos:** PostgreSQL con el esquema del Proyecto 1. En una base nueva, `db/01_schema.sql` crea el esquema aprobado. `db/02_datos_dominio.sql` y `db/03_datos_web.sql` cargan las entidades ficticias, datos web y permisos iniciales.

La autenticación usa tokens propios almacenados como filas `SESION_WEB`. El menú y los permisos se resuelven desde filas JSON de `confirmacion_operacion`, sin modificar el esquema.

## 3. Módulos

| Área | Funciones principales |
|---|---|
| Acceso | Registro, inicio y cierre de sesión, cambio de rol activo, cambio de contraseña (`PUT /api/auth/contrasena`), bitácora de intentos |
| Tienda | Catálogo filtrable, detalle y trazabilidad, carrito, cotización de envío y checkout |
| Cliente | Pedidos, pagos, cuotas, tarjetas, facturas, devoluciones y solicitud para ser productor |
| Productor | Ficha, producciones, ventas e indicadores propios |
| Personal | Pedidos, pagos, entregas, devoluciones, inventario, productos, categorías, productores, comunidades, producciones y compras |
| Administración | Usuarios, roles, permisos, bitácora, accesos, reportes e indicadores |
| Operación | Notificaciones y tarea programada para pedidos y lotes vencidos |

### Sistema visual

La interfaz ofrece apariencia infantil, adolescente y adulta, modos diurno y nocturno, alto contraste y escala de texto. Los temas comparten superficies, estados y foco en `frontend/src/styles/temas.css`. La marca transparente se selecciona por la luminancia del fondo real de cada ubicación. En la factura y el resumen se prepara la variante de letras oscuras antes de llamar a `window.print()`.

## 4. Seguridad y controles

- Validación de entrada mediante FormRequest con mensajes en español.
- Autorización por permiso y rol activo en middleware.
- Contraseñas con bcrypt y tokens almacenados como hash.
- Cambio de contraseña por el propio usuario (`PUT /api/auth/contrasena` con `contrasena_actual`, `contrasena_nueva` y
  `contrasena_nueva_confirmation`), que cierra sus demás sesiones.
- Las cuentas ficticias incluidas parten de una marca sin contraseña conocida. La persona que prepara la base genera claves individuales en su instalación mediante el comando del [README](../README.md).
- El inicio de sesión admite cinco intentos por minuto por combinación de correo e IP y treinta por IP. El registro
  admite cinco por minuto por IP. El cambio de contraseña admite diez solicitudes por minuto por cuenta. Tras cinco
  errores de contraseña actual, el cambio queda limitado durante quince minutos.
- Las visitas admiten sesenta solicitudes por minuto por cuenta autenticada o por IP para visitantes.
  En un aula los visitantes que comparten IP también comparten ese límite.
- Cierre de sesiones al desactivar una cuenta.
- Proxy inverso de confianza configurable (`TRUSTED_PROXIES`, `config/trustedproxy.php`): solo entonces se aceptan
  `X-Forwarded-For` y `X-Forwarded-Proto`, para registrar la IP real y reconocer HTTPS.
- Zona horaria de la sesión PostgreSQL fijada por `DB_TIMEZONE` (por defecto `America/La_Paz`).
- Consultas parametrizadas mediante Query Builder/Eloquent.
- Bitácora de accesos y operaciones sensibles.
- Protección contra doble ejecución mediante confirmaciones de operación.
- Bloqueo deliberado de `php artisan migrate` y comandos equivalentes.
- `.env` excluido del repositorio y del paquete de entrega.
- Pagos, QR y reembolsos simulados para fines académicos. No se procesan fondos reales.

## 5. Reglas principales

- Los lotes se eligen por **FEFO** (R026 y R037): primero
  el lote de origen que vence antes, los lotes sin vencimiento al final, y después fecha de ingreso e identificador
  (`InventarioRepository::ordenFefo`). Se aplica al reservar un pedido inmediato, al abastecer un encargo, al reasignar
  las reservas de un lote vencido y al sacar la reposición de una devolución.
- Un encargo se admite cuando falta stock y el producto permite encargo. Pasa a ESPERANDO_ABASTECIMIENTO cuando se
  confirma un pago o el cliente elige efectivo contra entrega. **Abastecer** reserva por FEFO todo lo que falta (o nada,
  con 409, si no alcanza el stock) y deja el encargo CONFIRMADO.
- Un pedido PENDIENTE sin pagos vence a las 48 horas: la compra inmediata por sus reservas y el encargo, que no
  reserva, por su fecha de creación. También vence el ENCARGO que ya espera abastecimiento sin ningún pago (ni PAGADO
  ni pendiente). El que tiene efectivo contra entrega aceptado no vence. La tarea `feder:vencer-pedidos` (cada
  5 minutos) lo cancela, libera sus reservas como EXPIRADA, cancela la entrega y el plan de cuotas, avisa al cliente y
  deja bitácora sin usuario. También pasa a VENCIDO los lotes caducados y reasigna sus reservas por FEFO.
- **Pagos por conciliar (R053):** un pago QR o TARJETA PENDIENTE puede haberse cobrado en el proveedor, así que vencer
  el plazo o cancelar no lo rechaza. La tarea deja ese pedido como está y lo informa como «pago por conciliar».
  La cancelación por personal responde 409 hasta que este confirme el cobro o lo rechace con motivo en Gestión de pagos.
  Si el cliente cancela un pedido pendiente e impago, el pago de pasarela queda por conciliar. Un cobro confirmado
  después de la cancelación genera un reintegro. El efectivo pendiente de un pedido que se cancela termina RECHAZADO, porque nunca se cobró.
- Una cuota posterior vencida bloquea nuevas compras una vez pagada al menos la primera cuota del plan. La primera cuota sin pagar se controla mediante el vencimiento del pedido.
- **Avance por el personal** (`POST /api/gestion/pedidos/{id}/avanzar` o `/api/gestion/entregas/{id}/avanzar`):
  CONFIRMADO → EN_PREPARACION → DESPACHADO → ENTREGADO, una transición por petición. Cada ruta exige su permiso y,
  además, cada transición exige el suyo, llegue por la ruta que llegue (`GestionPedidoService::PERMISO_POR_TRANSICION`):
  preparar y despachar, `pedidos.gestionar`. Entregar y cobrar el efectivo, `entregas.gestionar`.
- La petición de avance exige `hacia`, el estado de destino que mostraba la pantalla (`acciones.avanzar` en el detalle
  del pedido, `avanzar_a` en la lista de entregas). Sin él responde 422. Se compara con el pedido ya bloqueado: si no
  coincide (otra persona lo avanzó, la pestaña es antigua o la petición llegó repetida) responde 409 sin cambiar nada,
  y la pantalla vuelve a cargar el pedido o la lista de entregas.
- Preparar, despachar y entregar exigen el cobro resuelto (R047 y R057, `CobroPedidoService`): lo pagado igual al
  total, al centavo (un cobro mayor también detiene el avance). Efectivo contra entrega por el saldo exacto. O, por la
  regla de primera cuota, un plan de 2 o 3 cuotas semanales, numeradas desde 1, que suman el total de un pedido desde
  Bs 200, con la primera cuota pagada. Preparar exige además todo el stock reservado en lotes vigentes.
- El despacho consume cada reserva con su `SALIDA_VENTA` en la misma transacción. Con efectivo pendiente, la entrega
  exige confirmar que se recibió (`efectivo_confirmado`), no la registra el propio cliente del pedido (separación de
  funciones) y emite la factura en la misma transacción.
- **Cancelación por el personal**, con motivo, de un pedido PENDIENTE, ESPERANDO_ABASTECIMIENTO, CONFIRMADO o
  EN_PREPARACION: libera las reservas, cancela la entrega y el plan de cuotas y, por cada pago cobrado, registra un
  reintegro sin devolución (`reembolso` EJECUTADO, R031 y R052) por lo que aún no se reintegró. Repetirla no duplica
  nada. Con un pago QR o TARJETA pendiente responde 409 hasta conciliarlo (R053). Quien también es cliente no cancela
  su propio pedido si hay importe que reintegrar. El cliente solo cancela sus pedidos PENDIENTE sin pagos.
- El botón de pago envía la misma `clave_operacion` (UUID v4) al reintentar un intento con resultado desconocido.
  La API conserva también el uso sin clave. Reutilizarla con otro medio o cuota devuelve 409.
  En un plan se exige `cuota_numero` (422 si falta). Solicitar una cuota distinta de la siguiente o volver
  a solicitar la última después de saldarla con una clave nueva devuelve 409.
  Un reintento válido con la clave original devuelve el resultado registrado. Para sustituir un QR pendiente,
  el cliente declara que no lo pagó con `anular_qr_pendiente`. Una tarjeta pendiente se concilia en Gestión de pagos.
- Al recibir el pago, el servidor comprueba las 48 horas incluso si la tarea aún no se ejecutó. Esta regla también
  alcanza al encargo aceptado que sigue sin cobro ni efectivo contra entrega.
- La factura usa `FAC-AAAA-<id de factura>`, con al menos seis dígitos. R030 exige que los pagos PAGADO sumen el total original y que el pedido siga vigente. En cuotas se comprueba también la liquidación del plan. Si una devolución reduce la deuda, el estado de cuenta muestra los cobros, reducciones y reintegros. Una factura previa conserva su total. `facturas.anular` permite a Administración asentar el resultado de un procedimiento fiscal externo con su sustento y fecha, mediante una transacción con bitácora.
- `feder:abastecer-encargos` recupera los encargos y avisos pendientes cada minuto. Comparte servicio con el botón de Gestión y con la revisión posterior al commit de una recepción, liberación, reingreso o resolución del pago. Los encargos atendibles se ordenan por fecha de creación e identificador.
- La persona que confirma o rechaza un pago, cobra efectivo en la entrega, aprueba una devolución o completa el
  reembolso debe ser distinta del cliente de la operación.
- Una devolución no puede superar lo despachado menos lo ya devuelto. El reintegro en dinero queda limitado a lo
  cobrado disponible. En un plan, el resto del valor devuelto se descuenta desde la última cuota pendiente.
  Una cuota reducida a cero pasa a `ANULADA`. Si la devolución cancela toda la deuda pendiente, el plan queda
  `CERRADO_POR_DEVOLUCION`. El saldo mostrado al cliente suma únicamente las cuotas pendientes.
- Los productos medidos por unidad solo aceptan cantidades enteras.
- Una solicitud de productor es aprobada o rechazada por personal autorizado. Una rechazada puede reenviarse.
- Los códigos de negocio se generan con las secuencias ya existentes en la base.

## 6. API

La API está bajo `/api`. Los grupos principales son:

- `/auth`, `/catalogo`, `/carrito`, `/pedidos`, `/mis-pedidos`, `/mis-pagos`, `/planes-pago` y `/metodos-pago`.
- `/mis-facturas`, `/mis-devoluciones`, `/notificaciones` y `/productor/solicitud`.
- `/productor/ficha`, `/productor/producciones`, `/productor/ventas` y `/productor/indicadores`.
- `/gestion/pedidos`, `/gestion/pagos`, `/gestion/entregas`, `/gestion/devoluciones`, `/gestion/inventario`, `/gestion/productos`, `/gestion/categorias`, `/gestion/productores`, `/gestion/comunidades`, `/gestion/producciones` y `/gestion/compras`.
- `/admin/usuarios`, `/admin/permisos`, `/admin/bitacora`, `/panel` y `/reportes`.

La lista completa de rutas puede consultarse desde `backend/` con `php artisan route:list --path=api`.

## 7. Configuración, operación y pruebas

El [README](../README.md) explica cómo cargar el esquema y la semilla, configurar una instalación nueva y ejecutar las suites de PHP, PostgreSQL y React. El programador ejecuta `feder:abastecer-encargos` cada minuto y `feder:vencer-pedidos` cada cinco. Los reportes se exportan en CSV. La factura y el resumen del pedido se imprimen desde vistas con autorización.

La [guía de aceptación](GUIA_FLUJOS_Y_PENDIENTES_FEDER_P2.md) describe las cuentas, los recorridos, los importes de referencia y los límites externos del ejercicio.
