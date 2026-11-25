# FEDER Web P2 · Guía de flujos y pendientes para la aceptación del equipo

Para empezar, prepare una base ficticia exclusiva de aceptación, elija sus cuentas y anote el commit de su copia. D contiene los 14 recorridos. E reúne los rechazos esperados, F permite cuadrar los bolivianos y G recoge las decisiones abiertas. Use H para registrar incidencias y cerrar su sesión.

[Entorno](#entorno) · [Cuentas y roles](#roles) · [Recorridos](#recorridos) · [Pruebas negativas](#negativas) · [Facturas y saldos](#saldos) · [Pendientes](#pendientes) · [Acta de aceptación](#acta)

## A. Qué está comprobado y qué revisará el equipo

Los ensayos anteriores de la aplicación cubrieron el cambio entre contextos, las reservas, cuotas, devoluciones, la facturación y ocho cruces de concurrencia. El rediseño se recorrió en navegador con Visitante, Cliente, Productor, Personal y Administrador. Para impresiones, el navegador carga una imagen de marca específica para papel antes de abrir el diálogo. Las referencias de cada recorrido señalan pruebas o código que puede consultar en esta distribución.

El acta H empieza vacía. Registre allí únicamente lo observado en su instalación. Las cantidades y saldos de F son ejemplos calculados con datos ficticios. Anote las condiciones que encontró y conserve las diferencias, incluso cuando un escenario quede sin probar.

<a id="entorno"></a>

## B. Preparar su entorno de aceptación

Siga el [README](../README.md) para crear **una base nueva de demostración**, generar claves individuales, compilar la web e iniciar el servidor y el programador. Abra la dirección de su propio servidor y compruebe que `/api/ping` responde. La dirección de bucle local siempre apunta al equipo donde se abre el navegador.

Anote `git rev-parse HEAD`, la URL y el nombre de la base desechable en su acta privada. Para los recorridos con escrituras confirme que la instancia apunta a esa base. En una instancia compartida de solo consulta, limite el ensayo a las lecturas y registre las escrituras como **No probado**. El programador debe estar en marcha para observar el abastecimiento periódico de D10. Los pagos y reintegros de este ejercicio son registros de demostración.

<a id="roles"></a>

## C. Quién hace qué

La matriz indica el **alcance máximo**. Para actuar hacen falta cuenta y rol vigentes, el permiso concreto en la matriz y las condiciones de propiedad y estado de la operación. El menú lateral refleja el rol activo. En celular, **Abrir menú** muestra los mismos destinos autorizados. En el encabezado, la campana abre **Notificaciones** y el menú de la persona abre **Mi perfil**. En escritorio, **Apariencia** está en la cabecera. En pantalla estrecha se accede desde el menú.

| Contexto y acceso | Qué puede consultar | Qué puede crear o modificar | Cuándo interviene otra persona | Información fuera de su alcance |
|---|---|---|---|---|
| Visitante · Inicio, Catálogo y buscador | Artículos públicos y origen mostrado en sus fichas | Puede iniciar el registro de una cuenta | Una compra requiere ingreso y Cliente vigente | Carritos, pedidos, paneles y gestión privados |
| Cliente · Resumen (Mi cuenta) | Perfil, carrito, pedidos, pagos, cuotas, facturas, devoluciones y avisos propios | Perfil y métodos de pago propios, carrito, pedidos, pago simulado, solicitudes y cancelación permitida | FEDER concilia cobros, recibe el efectivo, resuelve devoluciones y ejecuta reintegros | Registros de otros clientes y gestión de FEDER |
| Productor · Mi panel productor | Mi ficha, Mis producciones, Mis lotes, Mis propuestas, Mis ventas a FEDER y Mis indicadores | Ficha propia en campos permitidos, producciones, lotes, ofertas y propuestas | FEDER revisa afiliación, cambio de comunidad y propuestas. Otro operador gestiona la compra, recepción y pago de sus lotes | Datos productivos ajenos, catálogo administrativo, agregados financieros y compras personales en ese rol |
| Personal de FEDER · Operaciones | Catálogo, comunidades, productores, compras, inventario, pedidos, cobros, entrega, posventa y reportes operativos concedidos | Mantiene artículos y comunidades, gestiona compras, recibe mercancía, avanza pedidos, registra cobros y resuelve posventa | Si también es interesado, otra persona valida su afiliación, su cobro, su devolución o reintegro y la compra de sus lotes | Gestión de permisos, bitácora administrativa y totales financieros globales |
| Administrador · Administración y operaciones concedidas | Usuarios, concesiones, permisos, bitácora, accesos e indicadores financieros agregados | Administra cuentas y permisos, realiza las operaciones autorizadas y registra anulaciones sustentadas | Se mantiene la separación por persona. Sus compras personales necesitan Cliente vigente y seleccionado | El rol administrativo por sí solo no abre un carrito ni las facturas de un tercero como si fueran propias |

**Dónde encontrar cada tarea en la interfaz de revisión**

| Contexto | Menú y ubicaciones |
|---|---|
| Cliente | **Resumen** abre Mi cuenta. **Pagos y cuotas** agrupa **Mis pagos**, **Planes de pago** y **Métodos de pago**. Carrito, Mis pedidos, Mis facturas y Mis devoluciones conservan accesos propios. |
| Productor | **Producción** reúne producciones, lotes y propuestas. **Ventas a FEDER** incluye ventas e indicadores propios. |
| Personal | **Venta y posventa** contiene **Gestión de pedidos**, **Cobros**, Entregas y Devoluciones. **Catálogo de FEDER** agrupa Productos y Categorías. **Abastecimiento** reúne productores, comunidades, compras, producciones e inventario. **Seguimiento** agrupa los paneles e informes concedidos. |
| Administrador | **Cuentas y permisos** reúne Usuarios y Permisos y menú. **Auditoría** contiene Bitácora y Accesos al sitio. Las tareas operativas siguen sus permisos efectivos. |

En móvil, use **Filtrar productos** en Catálogo o **Filtrar resultados** en los listados que lo ofrecen. En **Mis lotes**, **Ver detalle de…** expande los campos secundarios del registro. En el panel, **Ver cifras de…** muestra los valores del gráfico. En pedido, **Ver detalle del cálculo** muestra el desglose de importes. La confirmación de una acción sensible identifica el registro. **Volver sin cambios** cierra el diálogo y devuelve el foco al control que lo abrió.


### Cuentas ficticias de la semilla

Las direcciones con dominio `.example` representan personas ficticias de la demostración. Use las claves generadas en **su propia instalación** con el procedimiento del [README](../README.md). Mantenga ese archivo en privado.

| Cuenta | Roles previstos en la semilla | Uso en la guía |
|---|---|---|
| `ventas.jorge.suarez@feder.example` | Personal de FEDER y Cliente | Comprar como empleado y comprobar separación de funciones |
| `marcelina.tomicha@feder.example` | Productor y Cliente | Comprar con la misma identidad y preparar un lote propio |
| `silveria.masai@feder.example` | Productor y Cliente | Segunda productora para contrastar propiedad. Comprobar de nuevo sus concesiones en la copia de aceptación. |
| `almacen.carla.mendez@feder.example` | Solo Personal de FEDER | Operadora distinta y ejemplo sin Cliente |
| `daniela.flores@feder.example` y `marco.antelo@feder.example` | Solo Cliente en ambas | Dos titulares independientes |
| `administracion@feder.example` | Solo Administrador | Matriz, bitácora y registro de anulación |

Abra el **menú con el nombre de la persona y su rol** en el encabezado. Si tiene varios roles vigentes, aparece **Trabajar como**. Desde el profesional, **Comprar como cliente** selecciona la concesión existente y abre **Catálogo**. Desde ahí se llega a **Carrito** y **Mis pedidos**. Al volver al rol profesional se abre Inicio. Cambiar de rol mantiene la sesión y la identidad. Una cuenta con un solo rol no ve el selector. Conceder o revocar un rol corresponde al administrador autorizado. Quitar una función profesional conserva las restantes. Desactivar toda la cuenta impide el acceso de esa persona.

<a id="recorridos"></a>

## D. Recorridos de aceptación

Anote los códigos y las horas de su prueba. Use dos perfiles de navegador para separar a dos personas. Si un recorrido reutiliza un pedido, continúe desde el estado indicado. Para probar desenlaces distintos, cree pedidos distintos. Con recojo el costo de envío es cero. Antes de comprar, lea el precio y el stock libre **actuales** de su copia.

| Artículo ficticio de referencia | Precio en los ejemplos | Uso previsto |
|---|---:|---|
| Aceite de copaibo 100 ml | Bs 70 | Compra inmediata o encargo si hay faltante |
| Almendra chiquitana cruda 250 g | Bs 35 | Compra inmediata, cuotas y devolución |
| Aceite de totaí 100 ml | Bs 90 | Búsqueda y consulta de encargo |

**Lectura** en los recorridos significa que el usuario no ordena un cambio comercial. Visitar pantallas puede incrementar contadores, e ingresar o cambiar de rol registra actividad. **Escribe** indica cambios persistentes de la prueba en la base de aceptación.

### D1. Encontrar un producto como visitante · Lectura

**Objetivo, rol y preparación.** Compruebe que una persona sin sesión puede reconocer precio, presentación, disponibilidad y origen de un producto publicado. Abra una ventana sin sesión.

1. En **Catálogo**, escriba `totai` en **Buscar productos, categorías o comunidades…**. Espere las sugerencias. Seleccione **Aceite de totaí 100 ml**. También puede pulsar Intro para buscar en el catálogo y abrir la tarjeta.
2. Revise la ficha y el indicador de disponibilidad. Vuelva con **Volver al catálogo**. Busque un nombre inventado y único de al menos dos caracteres.

**Resultado y avisos.** La búsqueda encuentra «totaí» aun sin tilde. Con los datos de referencia, la ficha muestra Bs 90 y disponibilidad por encargo. Para un término ausente aparece «No encontramos resultados para …» en las sugerencias. El visitante puede usar **Iniciar sesión** o **Crear cuenta**. No cambia stock, pagos ni pedidos. **Señal de error** · ficha pública que exige sesión, artículo retirado que sigue a la venta o acceso a datos privados del cliente.

**Referencia para contrastar** · `BusquedaSinTildesTest.php`, `CatalogoFiltroSinTildesTest.php`. **Equipo · estado / observación / evidencia** ____________________

### D2. Comprar con una cuenta multirrol · Escribe sesión, carrito y pedido

**Objetivo, roles y preparación.** Pruebe una vez con Jorge y otra con Marcelina. Use un carrito de ensayo conocido y un artículo disponible. Carla sirve de comparación como Personal sin Cliente.

1. **Iniciar sesión → Correo electrónico → Contraseña → Ingresar**. En el menú del usuario seleccione el rol profesional si hace falta. Abra **Trabajar como → Comprar como cliente**.
2. Desde **Catálogo** agregue una unidad al carrito. Cambie al rol profesional, vuelva a Cliente y abra **Carrito**. Coteje artículo y cantidad.
3. Continúe con D6 hasta **Confirmar pedido**. Anote el código nuevo. Cambie de rol y vuelva a Cliente. Encuéntrelo en **Mis pedidos**. En una sesión separada de Carla observe las opciones del menú.

**Resultado y avisos.** El encabezado y el menú cambian al rol elegido. El carrito y el historial siguen ligados a esa persona. La compra confirmada queda a su nombre. Carla conserva exclusivamente su acceso profesional. Una concesión revocada tampoco se recupera al visitar Catálogo. **Señal de error** · necesitar otro correo o un segundo ingreso, perder el carrito, o poder comprar con una concesión ausente.

**Referencia para contrastar** · `CierreMultirrolTest.php`. **Equipo · estado / correos de demo y códigos / evidencia** ____________________

### D3. Publicar un producto y gestionar su foto · Escribe catálogo

**Objetivo, rol y preparación.** Use Personal con permiso de catálogo, por ejemplo Carla. Prepare dos JPEG o PNG distintos de hasta 5 MB, 4096 píxeles por lado y 8 megapíxeles. Elija un sufijo único para reconocer sus registros.

1. En **Categorías → Nueva categoría** cree `Muestras <sufijo>`, marque **Visible en el catálogo** y guarde. En **Productos → Nuevo producto** elija esa categoría. Use un código único `EQ-<sufijo>`, nombre `Miel muestra <sufijo>`, presentación `Frasco de muestra`, unidad, precio Bs 29 y stock mínimo 0. Marque **A la venta en el catálogo**, elija **Foto principal**, compare las vistas **Tarjeta · 4:3** y **Detalle · 1:1** y pulse **Guardar**.

2. Busque la miel por nombre en el catálogo público y abra la ficha. En gestión pulse **Editar**, cambie únicamente el precio a Bs 31 y guarde. Recargue la ficha. Seleccione luego la segunda foto en gestión y pulse **Cancelar**.
3. Repita el reemplazo con **Guardar**. Compruebe que se ve la segunda foto. Use **Editar → Retirar foto → Guardar** y recargue. **Deshacer cambio de foto** permite retirar una selección antes de guardar.
4. En **Categorías → Editar** desmarque **Visible en el catálogo**, guarde y abra el enlace de la ficha. Reactive la categoría y vuelva al mismo enlace. Para retirar solo el artículo use **A la venta en el catálogo**.

**Resultado y avisos.** «Producto creado.» y «Producto actualizado.» identifican los guardados. Cambiar el precio o cancelar conserva la primera foto. Antes de confirmar la retirada se lee «La foto se retirará al guardar». Después aparece el marcador de categoría. Ocultar y reactivar recupera la misma ficha. El alta de catálogo deja el stock físico intacto. **Señal de error** · archivo perdido tras cancelar o fallar el guardado, imagen compartida rota, ficha oculta que aún se vende o duplicación al reactivar.

**Referencia para contrastar** · `FotoProductoTest.php`. **Equipo · estado / código / evidencia** ____________________

### D4. Gestionar comunidad, afiliación, producción, lote y propuesta · Escribe gestión y actividad productiva

**Objetivo, roles y preparación.** Distinga los cambios que realiza una productora de los que debe aprobar FEDER. Use otra persona como Personal, una productora activa y un artículo que se venda por unidad. Para probar además una afiliación nueva, use un Cliente de ensayo sin solicitud previa.

1. Como Personal, **Comunidades → Nueva comunidad**. Cree un nombre único, municipio Roboré, departamento Santa Cruz y marque **Activa → Guardar**. Como cliente sin solicitud, abra **Mi perfil → Quiero ser productor**, indique comunidad y actividad y pulse **Enviar solicitud**. Otra persona abre **Productores → Revisar solicitud → Confirmar aprobación**. Si prueba el rechazo en otra solicitud, **Rechazar… → Confirmar rechazo** exige motivo.
2. Con una productora ya activa, abra **Mi ficha → Editar ficha**, cambie su ubicación y solicite una comunidad distinta. **Guardar cambios** actualiza la ubicación. Personal abre **Productores → Cambios de comunidad pendientes → Revisar cambio → Confirmar aprobación del cambio**. Recargue la ficha antes y después de la aprobación.
3. En **Mis producciones**, complete **Registrar nueva producción** con artículo activo, fechas coherentes, cantidad estimada 8 y ubicación de prueba. Pulse **Registrar producción**, anote el código y abra **Ver y gestionar → Iniciar producción → Agregar lote**. Indique elaboración hoy, vencimiento futuro, 8 unidades producidas, 6 ofrecidas y Bs 51 por unidad. Confirme **Agregar lote** y conserve su código para D5. Pruebe **Retirar oferta** y confirme en el diálogo en un *segundo* lote sin compromisos.
4. En **Mis propuestas**, complete **Proponer un producto** con nombre único, presentación y unidad. Pulse **Continuar ›**, indique categoría sugerida y descripción de al menos diez caracteres y elija **Enviar a revisión**. Como Personal, **Productos → Propuestas de productos pendientes → Completar y aprobar**. Complete el código, la categoría y el precio y pulse **Guardar**. Compruebe luego la propuesta desde la cuenta titular.

**Resultado y avisos.** La afiliación aprobada añade Productor y conserva Cliente. La comunidad previa sigue vigente hasta aprobar el cambio. «Producción registrada.» y «Lote registrado y ofrecido a FEDER.» identifican las altas. La oferta todavía pertenece al productor. La propuesta pasa de revisión a **Aprobada** y genera un solo artículo. **Señal de error** · autoaprobación, publicación de propuesta pendiente, reactivación involuntaria de una comunidad dada de baja o edición de una producción ajena.

**Referencia para contrastar** · `MantenimientosCierreTest.php`, `LoteCompradoTest.php`. **Equipo · estado / códigos de producción, lote y propuesta / evidencia** ____________________

### D5. Comprar a un productor y recibir en almacén · Escribe compra, inventario y registro de pago

**Objetivo, rol y preparación.** Use Personal distinto del titular, con permiso de compras. Localice el lote de D4 u otro con al menos cuatro unidades ofrecidas y vencimiento futuro. Anote stock físico y reservas antes de empezar.

1. En **Compras a productores → Registrar compra desde una oferta**, seleccione el código en **Lote ofrecido**, cantidad 4 y **Precio unitario (Bs)** 51. Elija **Entrega el productor** o **Recoge FEDER**. Pulse **Registrar compra** y anote su código. Se comprometen cuatro unidades por Bs 204.
2. Abra esa compra con **Ver y gestionar → Recepción en almacén**. Indique 2 recibidas, 0 rechazadas y 0 canceladas. Pulse **Guardar recepción**. Debe figurar **Recibida parcialmente** y haber entrado físicamente dos unidades.
3. Reabra la recepción. Los campos son **acumulados**: indique 3 recibidas, 1 rechazada y 0 canceladas. Guarde. Debe ingresar **una** unidad adicional. Compare **Inventario** y el total de la compra. Pulse **Registrar pago al productor → Confirmar registro del pago** y compruebe la fecha. La productora puede abrir **Ventas a FEDER → Mis ventas a FEDER → Ver compra**.

**Resultado y avisos.** «Recepción registrada.» y «Pago al productor registrado.» acompañan las acciones. La compra queda recibida por tres unidades aceptadas, Bs 153. Las unidades solo ofrecidas o rechazadas no ingresan al saldo físico. El disponible vendible puede disminuir si un encargo obtiene reserva. El pago al productor es un registro local. **Señal de error** · sumar tres encima de las dos anteriores, pagar con la compra aún abierta o permitir que la persona titular gestione su compra como operadora.

**Referencia para contrastar** · `RecepcionCompraTest.php`, `CierreMultirrolTest.php`, `OperacionProductorService.php`. **Equipo · estado / compra y lote / evidencia** ____________________

### D6. Crear un pedido inmediato · Escribe carrito, pedido y reservas

**Objetivo, rol y preparación.** Como Cliente, distinga agregar al carrito de reservar mercancía. Use un carrito de prueba vacío y una unidad disponible de **Aceite de copaibo 100 ml**. Anote su precio y stock libre iniciales.

1. En **Catálogo**, abra el producto, indique 1 y pulse **Agregar al carrito → Ver carrito**. Compruebe cantidad y precio.
2. Abra **Continuar con la compra**. En **Finalizar compra**, elija **Compra inmediata → Recojo en almacén**. Complete los datos de contacto, pulse **Continuar › → Un solo pago → Continuar ›** y revise el total antes de **Confirmar pedido**.
3. Anote el código que muestra **Detalle de tu pedido**. Si va a cotejar una respuesta de API, anote también el identificador que aparece en la dirección del detalle. Recargue y contraste el carrito y la disponibilidad del producto.

**Resultado y avisos.** «Tu pedido fue creado. Elige cómo deseas pagarlo.» El pedido queda **Pendiente**. Por Bs 70 de producto y recojo, debe Bs 70. Aún no hay cobro ni factura. La línea sale del carrito y la reserva, inicialmente con plazo de 48 horas, reduce una unidad del stock libre. El saldo físico sigue igual. **Señal de error** · cobro o salida de venta al crear, reserva al agregar al carrito, o dos pedidos por una sola confirmación.

**Referencia para contrastar** · `FefoTest.php`, `PedidoService.php`. **Equipo · estado / pedido y stock antes-después / evidencia** ____________________

### D7. Pactar y cobrar el efectivo · Escribe pago, entrega e historial

**Objetivo, roles y preparación.** Use el pedido de pago único de D6. Mantenga separadas la sesión Cliente y la de otro operador autorizado para pedidos y entregas. En esta copia se ensaya la declaración de cobro.

1. Como Cliente, en **Detalle de tu pedido** pulse **Efectivo → Pagar en efectivo al recibir**. Recargue. Anote por separado estado del pedido, estado del pago e importe.
2. El operador localiza el código en **Gestión de pedidos** y sigue D11 hasta despachar. Observe el requisito de confirmación que acompaña a **Marcar como entregado**. Intente avanzar sin confirmar el efectivo si la pantalla lo permite.
3. En el ensayo de cobro, marque **Confirmo que FEDER recibió [importe] en efectivo** y pulse **Marcar como entregado → Confirmar avance**. Revise pago, factura e historial. Variante con un pedido independiente: si el operador ya recibió el dinero, regístrelo antes desde **Cobros → Ver y gestionar → Confirmar: registrar cobro** y complete luego la entrega.

**Resultado y avisos.** Al pactarlo, el inmediato queda **Confirmado** con un pago **PENDIENTE** por Bs 70. Se respeta el efectivo acordado al revisar vencimientos. Si se intenta entregar con cobro pendiente y sin confirmación, la interfaz pide confirmar la recepción del dinero. Con cobro confirmado queda un solo pago **PAGADO**, entrega terminada, factura de Bs 70 y actor en bitácora. **Señal de error** · aceptación previa obligatoria, cobro al elegir efectivo, doble cobro al entregar o una persona cobrando su propio pedido.

**Referencia para contrastar** · `AvanceCobroTest.php`, `SeparacionFuncionesEntregaTest.php`. **Equipo · estado / pago y factura / evidencia** ____________________

### D8. Generar y confirmar un QR de demostración · Escribe intento y resultado local

**Objetivo, rol y preparación.** Cliente con un segundo pedido inmediato de pago único dentro de plazo. Use la copia de aceptación con `APP_ENV=local` y la simulación de pago habilitada según el README.

1. En **Detalle de tu pedido** elija **QR → Generar QR**. Compruebe el importe y el panel **Paga con QR** antes de confirmar nada.
2. Pulse **Simular pago (modo demostración)**. Si la opción falta, registre la configuración observada y deje el paso como **No probado**. Ningún paso requiere usar una aplicación bancaria.
3. Recargue el detalle y consulte **Pagos y cuotas → Mis pagos** y **Mis facturas**. Coteje el código del pedido y el importe.

**Resultado y avisos.** «Esperando la confirmación del banco…» indica que el intento sigue pendiente en la interfaz. Generarlo o refrescarlo conserva ese intento. La simulación local lo confirma, actualiza el inmediato y, con pago íntegro, emite una factura. **Señal de error** · registrar dinero al generar el QR o duplicar el cobro al recargar. Un resultado favorable aquí acredita únicamente el circuito local.

**Referencia para contrastar** · `QrPendienteTest.php`, `PanelPago.jsx`. **Equipo · estado / código de pago / evidencia** ____________________

### D9. Comprar y entregar en cuotas · Escribe plan, cuotas, pagos y entrega

**Objetivo, roles y preparación.** Cliente y operador distinto. Use seis unidades disponibles de **Almendra chiquitana cruda 250 g**, a Bs 35 cada una, y recojo. Para verificar la compra normal y la devolución de F, use pedidos distintos.

1. En **Finalizar compra** seleccione **Compra inmediata → Recojo en almacén → En cuotas**. En **Número de cuotas semanales** elija 2. Compruebe Bs 210 y pulse **Confirmar pedido**.
2. En el detalle compruebe dos cuotas de Bs 105. La primera vence en la fecha de creación y la segunda siete días después. En **Pagar cuota 1 de 2** pulse **QR → Generar QR → Simular pago (modo demostración)**. Conserve el identificador del pago.
3. Con el operador complete D11. Observe la deuda y las facturas **antes** de pagar la segunda cuota. Para el caso normal, pague la siguiente desde el mismo panel y consulte el resultado. Para B o C de F, tramite la devolución antes de pagar la siguiente.

**Resultado y avisos.** Desde Bs 200 se ofrecen dos o tres cuotas semanales. La tercera vence a los 14 días. El redondeo residual se asigna a la última cuota. La pantalla muestra importe, vencimiento y estado de cada una. La primera cuota pagada de un plan válido permite entregar si hay cobertura, con deuda de Bs 105 y aún sin factura. En el caso normal, dos cobros de Bs 105 liquidan la deuda y generan una sola factura de Bs 210. **Señal de error** · efectivo para cuotas, entrega sin primera cuota válida o factura emitida solo porque una reducción dejó el saldo en cero.

**Referencia para contrastar** · `PlanPrimeraCuotaTest.php`, `IdempotenciaPagoTest.php`. **Equipo · estado / pedido, cuotas y fechas / evidencia** ____________________

### D10. Abastecer un encargo al liberarse stock · Escribe dos pedidos, acuerdo, cancelación y reservas

**Objetivo, rol y preparación.** Cliente en una base aislada con el programador activo. Elija un artículo que admita encargo con stock libre entero entre 3 y 999. Evite otras compras simultáneas en esa copia para poder atribuir el resultado. El aceite de copaibo permite este ejemplo.

1. Anote el stock inicial **S**. Cree un pedido inmediato por **dos** unidades. Déjelo pendiente de pago y anótelo como I.
2. Con el mismo producto cree otro pedido por **S menos una unidad**. Con S = 100, quedan 98 libres y se encargan 99. Elija **Encargo**, recojo y pago único. Después de **Confirmar pedido** elija **Pagar en efectivo al recibir**. Anote el código E y compruebe **Esperando abastecimiento**. Al crear un encargo de varias líneas, cada artículo debe admitir encargo y presentar faltante.
3. Vuelva al pedido I. Indique **Motivo** y pulse **Cancelar pedido**. Abra E y recargue. Observe la confirmación automática y la notificación. Si se perdió la revisión inmediata, espere el siguiente ciclo por minuto. Deje **Abastecer encargo → Confirmar abastecimiento** para una variante manual independiente.
4. Para comprobar cobertura parcial, use en otra prueba un encargo con dos artículos que tengan faltante. Aporte cobertura para uno, por recepción o liberación válida, y examine el pedido antes de aportar la del segundo. Si faltan esas condiciones de prueba, anótelo.

**Resultado y avisos.** E mantiene precios y cantidades. Mientras falte alguna línea permanece en espera sin reservas parciales. Con cobertura completa pasa una vez a **Confirmado**, se reservan los lotes y aparece un aviso de llegada del encargo. El saldo físico aún no sale. Tienen prioridad los encargos atendibles más antiguos, con desempate por identificador. La misma revisión se aplica después de confirmar una recepción apta, un reingreso, la liberación de reservas o la resolución del pago. La tarea periódica recupera casos pendientes. **Señal de error** · reserva parcial, confirmación duplicada o necesidad de pulsar siempre el botón manual.

**Referencia para contrastar** · `AbastecimientoAutomaticoTest.php`. **Equipo · estado / stock, I y E / evidencia** ____________________

### D11. Preparar, despachar y entregar · Escribe historial, movimiento de inventario y entrega

**Objetivo, rol y preparación.** Otro Personal autorizado con un pedido confirmado de D7, D8 o D9. Se necesita el permiso de pedidos para preparar y despachar, y el de entregas para entregar. Anote las cantidades físicas y reservadas antes de avanzar.

1. En **Venta y posventa → Gestión de pedidos**, abra el código. Pulse **Empezar a preparar → Confirmar avance** con la cobertura y condición de pago resueltas.
2. En entrega a domicilio complete **Quién llevará el pedido**. Pulse **Despachar pedido → Confirmar avance**. En recojo siga el flujo correspondiente sin transportista de domicilio. Compruebe existencias y reservas después del despacho.
3. Pulse **Marcar como entregado → Confirmar avance**. Si queda efectivo pendiente, aplique D7. Recargue el detalle de entrega e historial.

**Resultado y avisos.** Secuencia **Confirmado → En preparación → Despachado → Entregado**. Se comprueba pago íntegro, efectivo acordado por el saldo o primera cuota válida del plan. Preparar requiere cobertura completa. Al despachar, cada reserva consumida registra su **SALIDA_VENTA** una sola vez. La selección FEFO usa primero el vencimiento más cercano, luego ingreso e identificador. Los lotes sin vencimiento quedan al final. Un lote con vencimiento de hoy aún está vigente. Ante cobertura insuficiente se indica el faltante y, según estado y permisos, puede ofrecerse **Reservar stock faltante**. **Señal de error** · saltar un estado, despachar un lote bloqueado o ya vencido, o descontar otra salida al entregar.

**Referencia para contrastar** · `DespachoInventarioTest.php`, `PermisoPorTransicionTest.php`, `AvanceCobroTest.php`. **Equipo · estado / cantidades e historial / evidencia** ____________________

### D12. Devolver, reingresar y reintegrar · Escribe devolución, inventario y saldos

**Objetivo, roles y preparación.** Cliente titular y otra persona con permiso de posventa. Use un pedido **Entregado** y un despacho propio con cantidad todavía devolvible. Para reproducir B de F, use seis almendras a Bs 35 entregadas después de cobrar solo la primera cuota.

1. En **Mis pedidos**, abra el detalle y pulse **Solicitar una devolución**. Elija producto y cantidad dentro del máximo mostrado, pulse **Continuar ›**, escriba el motivo de al menos cinco caracteres y elija **Enviar solicitud**. Anote el código recibido.
2. Otro operador abre **Devoluciones → Revisar → Recibir y aprobar…**. Elija **Reintegro del dinero**. Para B indique **Recibidas** 4, **Aptas para vender** 0 y **Aprobadas** 4. Pulse **Confirmar aprobación**. Antes de completar, el cliente debe recargar su estado de cuenta.
3. El operador vuelve a **Revisar → Confirmar: ejecutar reintegro**. El cliente recarga **Mis devoluciones**, el detalle del pedido, **Historial de reintegros** y notificaciones. Anote las cifras antes y después.
4. Variante con otro despacho: **Reposición del producto** y después **Confirmar: entregar reposición** consumen mercancía de sustitución si hay stock vendible. Para rechazar otra solicitud use **Rechazar… → Confirmar rechazo**, indicando el motivo.

**Resultado y avisos.** Para el reintegro se pasa de **Solicitada** a **Aprobada** y **Completada**. En B, al aprobar quedan Bs 105 pendientes de reintegro y Bs 35 de reducción de deuda. Al completar pasan Bs 105 a ejecutados. Recibida limita tanto aprobada como apta. Se reingresa como máximo la menor de apta y aprobada. Con cero aptas, el cliente puede tener derecho al dinero y el almacén recibe cero unidades vendibles. Se muestran confirmaciones de solicitud, aprobación y finalización y un aviso al titular. **Señal de error** · tratar un reintegro pendiente como ejecutado, duplicar el importe, reingresar mercancía no apta o aprobar la devolución propia.

**Referencia para contrastar** · `EstadoCuentaFacturaTest.php`, `DevolucionConPlanTest.php`, `SeparacionFuncionesPagosTest.php`. **Equipo · estado / despacho y devolución / evidencia** ____________________

### D13. Conservar y anular documentalmente una factura · Escribe cancelación y anulación

**Objetivo, roles y preparación.** Cliente titular, otro operador y Administrador con `facturas.anular` y acceso al pedido de gestión. Prepare una venta ficticia **pagada por completo, facturada y aún sin despachar**. Use una base de aceptación. Anote número de factura, código del pedido y fecha de emisión.

1. Como titular, **Mis facturas → Ver factura → Imprimir o guardar PDF**. Anote total, líneas y número antes de cualquier ajuste.
2. Como operador, en **Gestión de pedidos** abra el código, escriba **Motivo de cancelación** y pulse **Cancelar pedido** y confirme en el diálogo de gestión. Compruebe la liberación de reservas y el reintegro que corresponda. La factura emitida sigue **VIGENTE**. Este paso prueba la cancelación. La anulación documental también puede probarse sobre otra factura vigente.
3. Como Administrador abra el pedido en gestión y despliegue **Registrar anulación de factura**. Complete **Motivo** (3–500 caracteres), **Referencia del sustento** (obligatoria, hasta 250) y **Fecha y hora del sustento en Bolivia**. El momento debe quedar entre la emisión y la hora actual, incluidos los segundos. Use una referencia explícitamente ficticia para este ensayo. Pulse **Registrar anulación documentada**.
4. Recargue la factura como titular. Como administrador consulte **Bitácora**. Compare con los números e importes de los pasos 1 y 2.

**Resultado y avisos.** El registro de anulación existe. Exige una factura **VIGENTE** y guarda **ANULADA** con fecha. La respuesta de éxito indica «Se registró la anulación documentada de la factura.» El detalle se recarga. La factura conserva número, líneas y total. Bitácora guarda la acción **ANULAR**, el actor, factura, pedido, número, motivo, referencia y el estado y fecha antes y después. El registro de anulación por sí mismo no crea factura, pago ni reintegro. Una devolución aprobada se explica en el estado de cuenta. El trámite fiscal y la obtención de su sustento ocurren fuera de FEDER. **Señal de error** · cancelar o devolver anula automáticamente la factura, una repetición duplica el asiento, aparece una factura nueva o se acepta una fecha futura o anterior a la emisión.

**Referencia para contrastar** · `EstadoCuentaFacturaTest.php`. **Equipo · estado / pedido, factura y asiento / evidencia** ____________________

**Comprobación de impresión.** En una factura de ensayo ya emitida, abra **Mis facturas**, vea su detalle y pulse el botón de impresión. En el detalle de un pedido, abra **Imprimir resumen del pedido**. En cada diálogo seleccione **Guardar como PDF** y abra los dos archivos. Verifique el logo de letras oscuras sobre papel blanco, su proporción, los importes, la numeración de factura y los saltos de página. Repita desde apariencia nocturna. Abra la factura con su titular. El resumen también puede abrirse desde Gestión de pedidos con el permiso correspondiente. Los reportes se descargan en CSV.

### D14. Verificar administración, permisos efectivos y cifras financieras · Lectura y cambio reversible de permisos

**Objetivo, roles y preparación.** Use una sesión de Administrador y otra de Personal en una base exclusiva de aceptación. Anote la concesión inicial de **Reportes**. El cambio temporal afecta al rol completo en esa base. Si la matriz debe conservarse intacta, haga únicamente los pasos de lectura y marque el subcaso de revocación **No probado**.

1. Como Administrador abra **Cuentas y permisos → Usuarios**, consulte la cuenta de prueba y sus roles. Abra **Permisos y menú**, seleccione **Personal de FEDER** y distinga las casillas editables de las bloqueadas. Compruebe **Indicadores** como Administrador y busque **Ingresos cobrados (netos)**. La protección del último administrador cuenta también con prueba registrada.
2. Como Personal con permiso, abra **Reportes → Ventas cobradas → Descargar**. Registre el rango y compruebe que el CSV tiene filas de cobros con importes individuales. En esa misma sesión pruebe la pantalla `/indicadores`. No se deben mostrar agregados financieros globales.
3. En la copia exclusiva, si Personal tenía **Reportes**, desmárquelo y pulse **Guardar permisos de Personal de FEDER**. Vuelva a solicitar el reporte desde la sesión de Personal ya abierta. Después restaure la selección inicial y guarde. Desde **Bitácora** filtre por el usuario administrador y localice ambos cambios. Si el permiso ya estaba retirado, conserve esa configuración y anote el previo.
4. Recargue el menú de Personal y compruebe que se corresponde con la concesión efectiva. Revise que los importes por registro del CSV de ventas son brutos. Los reintegros se reflejan en el neto del panel.

**Resultado y avisos.** Antes de guardar aparece «Tienes cambios sin guardar.». Al guardar se informa la actualización. Una descarga autorizada muestra «Descargaste …». El servidor deniega el permiso retirado en la siguiente solicitud. El menú se actualiza al recargar. **Señal de error** · poder asignar una función prohibida, seguir descargando tras la revocación o ver totales globales desde Personal.

**Referencia para contrastar** · `PermisosReservadosTest.php`, `UltimoAdministradorTest.php`. **Equipo · estado / concesión inicial y restaurada / evidencia** ____________________

<a id="negativas"></a>

## E. Comprobaciones negativas

Usen únicamente cuentas y objetos de ensayo identificados por sus titulares. Las direcciones siguientes especifican **qué verificó la prueba de API**. Permiten cotejar la respuesta en las herramientas de red del navegador sin copiar credenciales, cookies ni cabeceras. Si el botón está oculto, eso prueba una condición de interfaz. **por sí solo no demuestra la autorización del servidor**. Anoten «UI observada / API respaldada por prueba previa» si no observan ambas. Un 403 deniega la operación, un 404 protege la existencia del registro, un 409 indica conflicto con el estado y un 422 indica un requisito incumplido.

| Caso que debe evaluar el equipo | Resultado visible y efecto que debe quedar | Contrato de API registrado y prueba |
|---|---|---|
| E1. Cliente abre la dirección de **Usuarios** de gestión | «Sin permiso» o acceso denegado. Ninguna lista de usuarios. | `GET /api/admin/usuarios` → 403. `PermisosReservadosTest.php`. |
| E2. Desde un segundo Cliente, abrir la dirección que el primer Cliente anotó para su pedido de ensayo | Se informa que el pedido no existe o no está disponible. Ni el detalle ni los importes del otro titular se revelan. | `GET /api/mis-pedidos/{pedidoAjeno}` → 404, mismo mensaje que para un pedido inexistente. `PropiedadDeRecursosTest.php`. |
| E3. Otra productora abre una producción de ensayo ajena e intenta editarla | La producción queda fuera de su listado. La consulta o el guardado deben denegarse sin cambiar la ficha del titular. | `GET` y `PUT /api/productor/producciones/{produccionAjena}` → 404. `PropiedadDeRecursosTest.php`. |
| E4. El empleado que compró trata de confirmar su propio pago o reintegro después de cambiar de rol | Debe intervenir otra persona. El pago o la devolución mantienen su estado. La prohibición de cobro propio se aplica aunque use otra ventana. | `POST /api/gestion/pagos/{pago}/confirmar` o `/rechazar` → 403. `POST /api/gestion/devoluciones/{devolucion}/aprobar` o `/completar` para reintegro propio → 403. Para un empleado productor que gestiona **sus propios lotes**, `POST /api/gestion/compras`, `POST /api/gestion/compras/{compra}/recibir` y `/pagar` → 403. `SeparacionFuncionesPagosTest.php`, `SeparacionFuncionesEntregaTest.php` y `CierreMultirrolTest.php`. |
| E5. Dos pestañas sobre el mismo pedido. Una avanza y la otra intenta el destino que ya se alcanzó | La pantalla antigua avisa del cambio de estado y recarga. Debe existir una transición, sin movimiento duplicado. Si se repite una anulación, queda una factura anulada y un asiento. | `POST /api/gestion/pedidos/{pedido}/avanzar` → 409 para el destino repetido. `POST /api/gestion/facturas/{factura}/anulacion` → 409 si ya estaba anulada. `AvanceCobroTest.php` y `EstadoCuentaFacturaTest.php`. |
| E6. Preparar sin cobro resuelto o sin cobertura suficiente | La acción no procede o el sistema explica el faltante. Estado, reserva y salida se conservan. Distingan un pedido **Pendiente** de un **Confirmado** con datos históricos incompletos. | `POST /api/gestion/pedidos/{pedido}/avanzar` → 409 al pedir preparación de un pendiente. 422 para un confirmado sin cobro resuelto. 409 si se necesita cobertura completa y falta. `AvanceCobroTest.php` y `DespachoInventarioTest.php`. |
| E7. Intentar el despacho con reserva de un lote bloqueado o que venció ayer | Se informa el impedimento. No debe consumirse esa reserva, registrarse una salida no apta ni pasar el pedido a despachado. Un vencimiento fechado hoy sigue vigente. Si no existe ese escenario, marque **No probado**. No altere el almacén para provocarlo. | `POST /api/gestion/pedidos/{pedido}/avanzar` → 409 en el caso registrado. `DespachoInventarioTest.php`. |
| E8. Intentar pagar una cuota ya cubierta desde una vista anterior | Un reintento reconocido recupera el pago anterior. Una nueva solicitud de cobro de la cuota cubierta se rechaza. Deben mantenerse importe cobrado, número de pagos y saldo correctos. | `POST /api/mis-pedidos/{pedido}/pagar` → 200 y `repetido=true` para el mismo intento identificado. 409 para otra solicitud de la cuota ya cubierta. `IdempotenciaPagoTest.php`. |
| E9. Con QR pendiente, elegir efectivo o tarjeta | **Elegir otro medio de pago** abre el selector. Al solicitar el cambio aparece la confirmación. **Conservar el QR** mantiene el intento. Solo en el ensayo de un QR que efectivamente no se pagó, **No pagué el QR. Anularlo y continuar con efectivo** permite pactar efectivo pendiente y dejar rechazado el QR anterior. Si la tarjeta de prueba es rechazada al cambiar, el QR permanece pendiente. | `POST /api/mis-pedidos/{pedido}/pagar` → 409 hasta resolver el QR. Una tarjeta heredada pendiente exige conciliación (409). Sustituir un efectivo ya acordado por tarjeta devuelve 422. `QrPendienteTest.php`. |

Si falta un dato previo o el estado del pedido ya cambió, registren el código, el estado observado y el subcaso que no se pudo ejercer. Mantengan la distinción entre un fallo reproducido en el ejercicio y uno que solo se esperaba detectar.

<a id="saldos"></a>

## F. Cuadrar factura, devoluciones y saldos

### Leer los importes antes de comparar

| Concepto en el detalle | Pregunta que contesta |
|---|---|
| Importe original, incluido el envío | ¿Cuánto totalizaba el pedido cuando se confirmó la compra? Se mantiene como referencia histórica. En los ejemplos el recojo deja el envío en cero. |
| Pagos efectivamente cobrados | ¿Cuánto suman los pagos registrados como **PAGADO**? Un QR generado o efectivo acordado aún pendiente no aumenta esta cifra. |
| Pendiente de pago | ¿Cuánto puede exigirse todavía? En cuotas se lee el saldo del plan después de reducciones y cobros. Al cancelar, la deuda comercial queda en cero. |
| Devoluciones con efecto económico aprobado | ¿Qué valor económico se ha reconocido? Puede repartirse entre dinero a reintegrar y reducción de deuda. |
| Reembolsos pendientes | ¿Cuánto queda comprometido por devolver? Puede haber un importe aprobado aún sin ejecución. |
| Reembolsos ejecutados | ¿Qué importe figura como efectivamente devuelto en FEDER? Un intento fallido conserva su rastro. No aumenta el total ejecutado. |
| Reducción aplicada a cuotas | ¿Cuánta deuda extinguió la devolución sin cobrar ese importe? Este ajuste no es una entrada ni una salida de dinero. |
| Cobro neto después de reintegros | Dinero cobrado menos reintegros ejecutados. Un reintegro pendiente todavía no disminuye el neto. |
| Importe histórico de factura | Cuando existe, es el total original de su única factura. Número, líneas e importe se conservan al devolver o registrar una anulación. |

### Tres pedidos de Bs 210, tres desenlaces

Cree **un pedido distinto por caso**, con recojo. Son ejemplos equivalentes tres aceites de copaibo a Bs 70 o seis almendras a Bs 35, con suficiente stock libre. Anote cada código. D7–D12 explican cómo pagar, entregar y devolver. Estas cifras proceden de la evidencia económica. El caso B también quedó recorrido en Edge con seis almendras.

| Caso | Pasos y momento a observar | Cobrado acumulado | Reintegro ejecutado | Reducción de deuda | Deuda al cierre | Cobro neto | Factura y salida visible |
|---|---|---:|---:|---:|---:|---:|---|
| A. Pago completo y devolución parcial | Pague Bs 210 en un solo pago y entregue. Devuelva Bs 140 (dos aceites o cuatro almendras). Al aprobar, verifique Bs 140 pendientes de reintegro. Después, ejecute el reintegro. | 210 | 140 | 0 | 0 | 70 | Se conserva la factura original de Bs 210. El estado de cuenta expone el ajuste. |
| B. Devolución entre dos cuotas | Cobre Bs 105 y entregue. Apruebe la devolución de Bs 140: Bs 105 para reintegro y Bs 35 para reducir la segunda cuota. Ejecute el reintegro. Verifique que quedan Bs 70 de deuda y pague esa última cuota. | 175 | 105 | 35 | 0 | 70 | **Resumen de liquidación**, sin factura. La suma de cobros está por debajo de Bs 210. |
| C. Devolución total después de la primera cuota | Cobre Bs 105 y entregue. Devuelva el pedido completo, Bs 210. Al aprobar se extinguen los Bs 105 restantes. Ejecute el reintegro de los Bs 105 cobrados. | 105 | 105 | 105 | 0 | 0 | Cuota restante anulada por devolución, plan **Cerrado por devolución**, resumen sin factura. |

En las tres filas **el original es Bs 210 y al terminar quedan Bs 0 pendientes de reintegro**. Una prueba adicional del cierre distinguió un intento pendiente de uno fallido y un reintegro anterior de Bs 30: el reintento ejecutó solo los Bs 110 restantes de Bs 140. Conserven los estados intermedios al reportar una discrepancia. Un reintento pendiente de conciliación no debe presentarse como dinero ya devuelto.

**Cuándo emitir.** R030 exige que la suma de pagos **PAGADO** iguale el total original, con el pedido sin cancelar. En un plan también se comprueban su liquidación y los pagos que respaldan las cuotas. Poder entregar tras la primera cuota no satisface la condición de emisión. **Imprimir resumen del pedido** imprime los movimientos. Si la reducción liquidó la deuda con cobros menores que el original, el encabezado es **Resumen de liquidación**. **Registrar anulación de factura** es una actuación administrativa separada y ya implementada, con condiciones, documentación y efectos descritos en D13.

**Control del equipo** · A — pedido, número de factura y estado de cuenta __________ · B — pedido, deuda intermedia y resumen __________ · C — pedido, cuota anulada y resumen __________.


### Dónde contrastar reglas y resultados

| Área | Código y pruebas incluidos |
|---|---|
| Navegación, propiedad y permisos | [Menú de usuario](../frontend/src/components/layout/MenuUsuario.jsx), [máximos por rol](../backend/app/Support/PoliticaPermisos.php), [rutas](../backend/routes/api.php) y [pruebas de acceso](../backend/tests/Integracion/Acceso). |
| Fotos y catálogo | [Pruebas de integración](../backend/tests/Integracion) y [pruebas de interfaz](../frontend/tests). |
| Inventario, cobros y facturas | [Pruebas de pedidos](../backend/tests/Integracion/Pedidos), [pruebas de pagos](../backend/tests/Integracion/Pagos) y [servicios de negocio](../backend/app/Services). |
| Marca y salida en papel | [Componente de marca](../frontend/src/components/ui/MarcaFeder.jsx) y [control de impresión](../frontend/src/components/ui/BotonImprimir.jsx). |

<a id="pendientes"></a>

## G. Pendientes de aceptación, decisión o validación externa

| Asunto | Estado registrado | Qué debe evaluar el equipo |
|---|---|---|
| Defectos en los 14 flujos | En el cierre previo no quedó un defecto reproducible abierto en esos recorridos. La aceptación independiente sigue pendiente. | Documentar en H cada discrepancia concreta. |
| Anulación documentada y saldos A/B/C | Implementados. Aceptación económica pendiente, prioridad alta. | Cotejar número, fechas, actor, pagos, deuda, reintegros y el efecto de repetir la operación. |
| Multirrol, efectivo y abastecimiento | Implementados. Aceptación operativa pendiente, prioridad alta. | Probar los menús con dos personas, el efectivo contra entrega y la reserva automática completa. |
| Contraste del resumen de pedido impreso | En **Adultos → Noche**, el título y algunas etiquetas del PDF se imprimen con un contraste de 2,14:1 sobre papel blanco. Los importes y el logo conservan el contraste. | Para imprimirlo, seleccione **Apariencia → Adultos → Día** y pulse **Imprimir resumen del pedido**. Se comprobó un contraste de 6,55:1 en esas etiquetas. Queda pendiente uniformar los estilos de impresión. |
| Interrupción anterior de PostgreSQL | Incidente recuperado en un entorno de prueba anterior. La causa inicial quedó sin determinar. | Si reaparece, anotar hora, versión, respuesta de la API y estado del servidor y del programador. Prioridad alta si impide el uso. |
| Bloqueo y desbloqueo manual de lotes | Pendiente de decisión. El motor excluye del despacho y de las reservas el lote bloqueado. | Acordar quién puede registrar y levantar la incidencia, y con qué sustento. |
| Merma o baja física de unidades deterioradas | Pendiente de decisión. La exclusión por vencimiento y el reingreso apto ya están diferenciados. | Definir responsable y evidencia para una baja física por merma. |
| Ajuste después de un conteo | Pendiente de decisión. Las recepciones y movimientos autorizados determinan el stock. | Definir el alcance de un ajuste extraordinario, su motivo, sus límites y autoría. |
| PagoFácil, cobros y conciliación bancaria reales | Pendiente de validación externa antes de uso real. QR, tarjetas y reintegros del laboratorio son simulados. | Validar con el proveedor y la entidad autorizados en un entorno destinado a pagos reales. |
| Instalación institucional en TecnoWeb | Pendiente de validación externa antes de desplegar. | Verificar con autorización el aislamiento, las rutas y la configuración del servidor institucional. |
| Trámite fiscal externo | Pendiente de validación externa. FEDER registra una anulación documentada. | Acordar el documento de sustento y su responsable antes del uso fiscal. |
| Catálogo con varias fotografías | Ampliación opcional. Actualmente existe una foto principal por producto. | Aceptar el comportamiento de D3 y registrar por separado cualquier propuesta de galería. |

<a id="acta"></a>

## H. Registrar y cerrar la aceptación

### Plantilla de incidencia

| Campo | Anotación del equipo |
|---|---|
| Fecha, equipo de prueba, rama y commit | |
| URL/puerto y nombre de la base de aceptación | |
| Rol activo y persona de prueba que intervino | |
| Recorrido, pantalla y botón | |
| Datos previos relevantes | Código de pedido, lote, factura o devolución. Estado, importe y unidades iniciales. |
| Pasos realizados | 1. … 2. … 3. … |
| Resultado esperado y regla o referencia | |
| Resultado obtenido | Mensaje literal. Estado, importes, reservas y movimientos después del intento. |
| Evidencia | Captura recortada o estado HTTP. Omitir contraseñas, tokens, cookies, datos personales y cabeceras de autenticación. |
| Repetición e impacto | ¿Se repite en otro registro de ensayo? ¿Bloquea el flujo, altera dinero o stock, o solo afecta la presentación? |
| Hipótesis por investigar | Indicarla aparte de los hechos observados. |

### Lista de aceptación del compañero

Marque **una** casilla por fila y anote los identificadores generados o el motivo de **No probado**. Si solo se hizo una parte, deje la fila en **No probado** y registre qué subcaso sí se observó. Un fallo reproducido merece una ficha H.

| Recorrido | Aprobado | Falló | No probado | Evidencia / observación |
|---|:---:|:---:|:---:|---|
| D1 · Visitante y catálogo | ☐ | ☐ | ☐ | |
| D2 · Compra multirrol | ☐ | ☐ | ☐ | |
| D3 · Categorías, publicación y foto | ☐ | ☐ | ☐ | |
| D4 · Comunidad, productor, producción, lote y propuesta | ☐ | ☐ | ☐ | |
| D5 · Compra a productor, recepción y pago | ☐ | ☐ | ☐ | |
| D6 · Pedido inmediato y reserva | ☐ | ☐ | ☐ | |
| D7 · Efectivo y cobro | ☐ | ☐ | ☐ | |
| D8 · QR simulado | ☐ | ☐ | ☐ | |
| D9 · Cuotas y entrega con primera cuota | ☐ | ☐ | ☐ | |
| D10 · Encargo y cobertura completa | ☐ | ☐ | ☐ | |
| D11 · Preparación, despacho y entrega | ☐ | ☐ | ☐ | |
| D12 · Devolución, reingreso y reintegro | ☐ | ☐ | ☐ | |
| D13 · Factura y anulación documentada | ☐ | ☐ | ☐ | |
| D14 · Administración, permisos y finanzas | ☐ | ☐ | ☐ | |
| E1–E3 · Gestión y propiedad | ☐ | ☐ | ☐ | |
| E4–E5 · Separación y repetición | ☐ | ☐ | ☐ | |
| E6–E7 · Avance, cobertura y vencimiento | ☐ | ☐ | ☐ | |
| E8–E9 · Cuota repetida y cambio de medio | ☐ | ☐ | ☐ | |
| F · Caso A | ☐ | ☐ | ☐ | |
| F · Caso B | ☐ | ☐ | ☐ | |
| F · Caso C | ☐ | ☐ | ☐ | |
