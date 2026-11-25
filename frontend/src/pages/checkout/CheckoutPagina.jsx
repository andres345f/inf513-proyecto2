import { useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { Bike, Car, CreditCard, LocateFixed } from 'lucide-react'
import { comercioApi } from '../../api/comercioApi'
import ModalMetodoPago from '../../components/cliente/ModalMetodoPago'
import MapaUbicacion from '../../components/comercio/MapaUbicacion'
import PlantillaFormulario from '../../components/plantillas/PlantillaFormulario'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import CampoTexto from '../../components/ui/CampoTexto'
import Cargando from '../../components/ui/Cargando'
import Tarjeta from '../../components/ui/Tarjeta'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { formatearMoneda, formatearNumero } from '../../utils/formato'
import { enfocarPrimerError, erroresDeApi, longitudMaxima, requerido, soloLetras, telefonoBoliviano, validar } from '../../utils/validaciones'
import { MODALIDADES } from '../../utils/etiquetas'
import './CheckoutPagina.css'

const MINIMO_CUOTAS = 200
const PASOS = ['Entrega', 'Pago', 'Revisión']

const REGLAS = {
  nombre_receptor: [requerido('Indica quién recibirá el pedido.'), soloLetras('El nombre'), longitudMaxima(120, 'El nombre')],
  telefono_contacto: [requerido('Indica un teléfono de contacto.'), telefonoBoliviano()],
}

/**
 * Checkout como asistente de tres pasos (Fase B):
 *   1) Entrega — tipo de pedido, modalidad, dirección y quién recibe.
 *   2) Pago — pago único o en cuotas; alta de tarjeta en el momento si corresponde.
 *   3) Revisión — resumen completo antes de confirmar.
 * La creación del pedido usa POST /api/pedidos (comercioApi.confirmarPedido):
 * los importes y estados los calcula siempre el servidor.
 */
export default function CheckoutPagina() {
  const { sesion, tienePermiso } = useSesion()
  const navegar = useNavigate()
  const carrito = useCargaApi(() => comercioApi.carrito(), [])
  const [paso, setPaso] = useState(0)
  const [datos, setDatos] = useState({
    tipo: '', modalidad: 'RECOJO', direccion: '', referencia: '', observacion: '',
    nombre_receptor: sesion.usuario.nombre_completo, telefono_contacto: String(sesion.usuario.telefono ?? ''),
    forma_pago: 'UNICO', num_cuotas: 2,
  })
  const [destino, setDestino] = useState(null)
  const [cotizacion, setCotizacion] = useState({ datos: null, cargando: false, error: null })
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const [modalTarjeta, setModalTarjeta] = useState(false)
  const sugerida = useRef('')
  const formularioActual = useRef(null)
  const peticionDestino = useRef(0)
  const [direccionAutomatica, setDireccionAutomatica] = useState(false)

  const resumen = carrito.datos

  /**
   * Sugiere la dirección del punto marcado. Solo reemplaza el campo si está vacío o si todavía tiene
   * la sugerencia anterior: nunca pisa lo que el cliente escribió. Si falla, el campo queda para escribirlo a mano.
   */
  const sugerirDireccion = (punto, peticion) => {
    comercioApi.direccionDe(punto.lat, punto.lng)
      .then(({ direccion }) => {
        if (!direccion || peticion !== peticionDestino.current) return
        setDatos((d) => (d.direccion === '' || d.direccion === sugerida.current
          ? { ...d, direccion, referencia: direccion }
          : { ...d, referencia: d.referencia || direccion }))
        sugerida.current = direccion
        setDireccionAutomatica(true)
      })
      .catch(() => {})
  }

  const elegirDestino = async (punto) => {
    setDestino(punto)
    const peticion = ++peticionDestino.current
    sugerirDireccion(punto, peticion)
    setErrores((actual) => ({ ...actual, mapa: null }))
    setCotizacion({ datos: null, cargando: true, error: null })
    try {
      const datosEnvio = await comercioApi.cotizarEnvio(punto.lat, punto.lng)
      if (peticion === peticionDestino.current) setCotizacion({ datos: datosEnvio, cargando: false, error: null })
    } catch (falla) {
      if (peticion === peticionDestino.current) setCotizacion({ datos: null, cargando: false, error: falla.mensaje })
    }
  }

  if (carrito.cargando) return <Cargando texto="Preparando tu compra…" />
  if (carrito.error) return <section className="checkout"><h1>Finalizar compra</h1>
    <Alerta tipo="error">{carrito.error}</Alerta><Boton variante="secundario" onClick={carrito.recargar}>Reintentar</Boton>
  </section>
  if (!resumen || resumen.items.length === 0) return <Navigate to="/carrito" replace />

  const cambiar = (evento) => {
    const { name, value } = evento.target
    setDatos((d) => ({ ...d, [name]: value }))
    setErrores((e) => ({ ...e, [name]: null }))
    // Al volver a recojo, la ubicación y su cotización ya no aplican: se limpian
    // para que confirmación envíe null y no datos residuales del mapa.
    if (name === 'modalidad' && value === 'RECOJO') {
      peticionDestino.current += 1
      setDestino(null)
      setCotizacion({ datos: null, cargando: false, error: null })
      setErrores((e) => ({ ...e, mapa: null }))
    }
  }

  const usarMiUbicacion = () => {
    if (!navigator.geolocation) return setCotizacion({ datos: null, cargando: false, error: 'Tu navegador no permite obtener la ubicación.' })
    navigator.geolocation.getCurrentPosition(
      (p) => elegirDestino({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => setCotizacion({ datos: null, cargando: false, error: 'No pudimos obtener tu ubicación. Márcala en el mapa.' }),
    )
  }

  const costoEnvio = datos.modalidad === 'DELIVERY' ? (cotizacion.datos?.costo ?? 0) : 0
  const total = Math.round((resumen.subtotal + costoEnvio) * 100) / 100
  const admiteCuotas = total >= MINIMO_CUOTAS
  const tipoPedido = datos.tipo || (resumen.todo_con_stock ? 'INMEDIATO' : 'ENCARGO')

  /** Un delivery solo es confirmable con una cotización real, cargada y dentro de cobertura. */
  const hayCotizacionValida = datos.modalidad === 'DELIVERY'
    && !cotizacion.cargando
    && !cotizacion.error
    && cotizacion.datos
    && !cotizacion.datos.fuera_de_cobertura

  /** Validación del paso de entrega (paso 1). */
  const validarEntrega = () => {
    const fallos = validar(datos, {
      ...REGLAS,
      ...(datos.modalidad === 'DELIVERY' ? { direccion: [requerido('Escribe la dirección de entrega.'), longitudMaxima(255, 'La dirección')] } : {}),
    })
    if (datos.modalidad === 'DELIVERY') {
      if (!destino) fallos.mapa = 'Marca tu ubicación en el mapa.'
      else if (cotizacion.cargando) fallos.mapa = 'Estamos calculando el costo del envío; espera unos segundos e inténtalo otra vez.'
      else if (cotizacion.error) fallos.mapa = 'No pudimos calcular el costo del envío: marca tu ubicación en el mapa de nuevo.'
      else if (!cotizacion.datos) fallos.mapa = 'Necesitamos calcular el costo del envío del lugar marcado.'
      else if (cotizacion.datos.fuera_de_cobertura) fallos.mapa = 'Esa ubicación está fuera de nuestra zona de entrega.'
    }
    return fallos
  }

  const confirmar = async () => {
    setErrorGeneral(null)
    const fallos = validarEntrega()
    setErrores(fallos)
    if (Object.keys(fallos).length > 0) { setPaso(0); return setErrorGeneral('Revisa los datos de entrega antes de confirmar.') }
    // Última verificación: sin cotización válida no hay creación de pedido delivery.
    if (datos.modalidad === 'DELIVERY' && !hayCotizacionValida) {
      setPaso(0)
      setErrores({ mapa: 'Vuelve a calcular el costo del envío antes de confirmar.' })
      return setErrorGeneral('No pudimos confirmar el costo del envío.')
    }

    setEnviando(true)
    try {
      const pedido = await comercioApi.confirmarPedido({
        tipo: tipoPedido,
        modalidad: datos.modalidad,
        // En recojo no viajan datos de ubicación: exactamente null.
        direccion: datos.modalidad === 'DELIVERY' ? datos.direccion : null,
        referencia: datos.modalidad === 'DELIVERY' ? (datos.referencia || null) : null,
        lat: datos.modalidad === 'DELIVERY' ? (destino?.lat ?? null) : null,
        lng: datos.modalidad === 'DELIVERY' ? (destino?.lng ?? null) : null,
        nombre_receptor: datos.nombre_receptor,
        telefono_contacto: datos.telefono_contacto,
        observacion: datos.observacion || null,
        forma_pago: admiteCuotas ? datos.forma_pago : 'UNICO',
        num_cuotas: admiteCuotas && datos.forma_pago === 'CUOTAS' ? Number(datos.num_cuotas) : null,
      })
      navegar(`/mis-pedidos/${pedido.id}`, { replace: true, state: { recienCreado: true } })
    } catch (falla) {
      const campos = erroresDeApi(falla)
      for (const campo of ['lat', 'lng', 'referencia']) {
        if (campos[campo]) { campos.mapa ??= campos[campo]; delete campos[campo] }
      }
      setErrores(campos)
      const pagoInvalido = campos.forma_pago || campos.num_cuotas
      if (Object.keys(campos).length) {
        setPaso(pagoInvalido ? 1 : 0)
        enfocarPrimerError(formularioActual.current)
      }
      setErrorGeneral(falla.mensaje)
    } finally {
      setEnviando(false)
    }
  }

  const alEnviar = async (evento) => {
    formularioActual.current = evento.currentTarget
    if (paso === 0) {
      const fallos = validarEntrega()
      setErrores(fallos)
      setErrorGeneral(Object.keys(fallos).length > 0 ? 'Revisa los datos de entrega para continuar.' : null)
      if (Object.keys(fallos).length === 0) setPaso(1)
      else enfocarPrimerError(formularioActual.current)
      return
    }
    if (paso === 1) {
      setErrorGeneral(null)
      setPaso(2)
      return
    }
    await confirmar()
  }

  const retroceder = () => {
    setErrorGeneral(null)
    setPaso((p) => Math.max(0, p - 1))
  }

  const ayudaDePaso = paso === 0
    ? 'Elige cómo recibirás tu pedido. Si es delivery, marca tu ubicación en el mapa.'
    : paso === 1
      ? 'Elige cómo vas a pagar. Puedes dejar una tarjeta guardada para las próximas compras.'
      : 'Revisa los productos, la entrega y el total antes de confirmar.'

  return (
    <>
      <PlantillaFormulario
        className="checkout"
        titulo="Finalizar compra"
        pasos={PASOS}
        pasoActual={paso}
        ayuda={ayudaDePaso}
        onSubmit={alEnviar}
        cargando={enviando}
        avisoError={errorGeneral ? <Alerta tipo="error">{errorGeneral} {Object.values(errores).some(Boolean) && (
          <button type="button" className="checkout__resumen-error" onClick={() => enfocarPrimerError(formularioActual.current)}>
            Revisar campos marcados ({Object.values(errores).filter(Boolean).length})
          </button>
        )}</Alerta> : null}
        avisoExito={null}
        pie={
          <>
            {paso > 0 && (
              <Boton variante="secundario" onClick={retroceder} disabled={enviando}>
                ‹ Volver a {PASOS[paso - 1].toLowerCase()}
              </Boton>
            )}
            {paso < PASOS.length - 1 && <Boton tipo="submit">Continuar ›</Boton>}
            {paso === PASOS.length - 1 && <Boton tipo="submit" cargando={enviando}>Confirmar pedido</Boton>}
          </>
        }
      >
        {paso === 0 && (
          <>
            <Tarjeta titulo="Tipo de pedido">
              <div className="checkout__opciones" role="group" aria-label="Tipo de pedido" tabIndex={-1}
                aria-invalid={Boolean(errores.tipo)} aria-describedby={errores.tipo ? "checkout-tipo-error" : undefined}>
                <Opcion nombre="tipo" valor="INMEDIATO" actual={tipoPedido} alCambiar={cambiar} deshabilitada={!resumen.todo_con_stock}
                  titulo="Compra inmediata" detalle={resumen.todo_con_stock ? 'Hay stock: reservamos tus productos.' : 'No hay stock suficiente de todo.'} />
                <Opcion nombre="tipo" valor="ENCARGO" actual={tipoPedido} alCambiar={cambiar}
                  deshabilitada={resumen.todo_con_stock || !resumen.todo_admite_encargo}
                  titulo="Encargo" detalle="Tu pedido espera hasta que FEDER pueda reservar todos los productos." />
              </div>
              {errores.tipo && <p id="checkout-tipo-error" className="checkout__error" role="alert">{errores.tipo}</p>}
            </Tarjeta>

            <Tarjeta titulo="Modalidad de entrega">
              <div className="checkout__opciones" role="group" aria-label="Modalidad de entrega" tabIndex={-1}
                aria-invalid={Boolean(errores.modalidad)} aria-describedby={errores.modalidad ? "checkout-modalidad-error" : undefined}>
                <Opcion nombre="modalidad" valor="RECOJO" actual={datos.modalidad} alCambiar={cambiar}
                  titulo="Recojo en almacén" detalle="Sin costo. San Joaquín 2052, Santa Cruz de la Sierra." />
                <Opcion nombre="modalidad" valor="DELIVERY" actual={datos.modalidad} alCambiar={cambiar}
                  titulo="Envío a domicilio" detalle="Costo según distancia por calles y peso." />
              </div>
              {errores.modalidad && <p id="checkout-modalidad-error" className="checkout__error" role="alert">{errores.modalidad}</p>}

              {datos.modalidad === 'DELIVERY' && (
                <div className="checkout__delivery">
                  <button type="button" className="checkout__ubicacion" onClick={usarMiUbicacion} aria-invalid={Boolean(errores.mapa)} aria-describedby={errores.mapa ? "checkout-mapa-error" : undefined}>
                    <LocateFixed size={16} aria-hidden="true" /> Usar mi ubicación actual
                  </button>
                  <MapaUbicacion destino={destino} origen={cotizacion.datos?.origen} alElegir={elegirDestino} />
                  {errores.mapa && <p id="checkout-mapa-error" className="checkout__error" role="alert">{errores.mapa}</p>}
                  <Cotizacion estado={cotizacion} />
                  <CampoTexto etiqueta="Dirección" nombre="direccion" obligatorio value={datos.direccion} onChange={cambiar}
                    error={errores.direccion} placeholder="Calle, número, barrio" maxLength={255}
                    ayuda={direccionAutomatica ? 'La completamos a partir del punto del mapa. Corrígela o agrega el número si hace falta.' : 'Al marcar tu ubicación en el mapa la completamos por ti.'} />
                  <p className="checkout__ayuda">Referencia detectada automáticamente: {datos.referencia || 'marca un punto en el mapa'}</p>
                </div>
              )}

              <div className="checkout__fila">
                <CampoTexto etiqueta="Quién recibe" nombre="nombre_receptor" obligatorio value={datos.nombre_receptor}
                  onChange={cambiar} error={errores.nombre_receptor} maxLength={120} />
                <CampoTexto etiqueta="Teléfono" nombre="telefono_contacto" tipo="tel" inputMode="numeric" obligatorio
                  value={datos.telefono_contacto} onChange={cambiar} error={errores.telefono_contacto} maxLength={8} />
              </div>
            </Tarjeta>

            <CampoTexto etiqueta="Descripción del lugar (opcional)" nombre="observacion" value={datos.observacion} error={errores.observacion}
              onChange={cambiar} maxLength={500} ayuda="Ejemplo: casa verde, portón negro o indicaciones para encontrar la entrada." />
          </>
        )}

        {paso === 1 && (
          <Tarjeta titulo="Forma de pago">
            <div className="checkout__opciones" role="group" aria-label="Forma de pago" tabIndex={-1} aria-invalid={Boolean(errores.forma_pago)} aria-describedby={errores.forma_pago ? "checkout-forma-error" : undefined}>
              <Opcion nombre="forma_pago" valor="UNICO" actual={datos.forma_pago} alCambiar={cambiar}
                titulo="Un solo pago" detalle="QR, tarjeta o efectivo contra entrega." />
              <Opcion nombre="forma_pago" valor="CUOTAS" actual={datos.forma_pago} alCambiar={cambiar} deshabilitada={!admiteCuotas}
                titulo="En cuotas" detalle={admiteCuotas ? 'Recibes tu pedido al pagar la primera cuota.' : `Disponible desde ${formatearMoneda(MINIMO_CUOTAS)}.`} />
            </div>
            {errores.forma_pago && <p id="checkout-forma-error" className="checkout__error" role="alert">{errores.forma_pago}</p>}
            {admiteCuotas && datos.forma_pago === 'CUOTAS' && (
              <fieldset className="checkout__cuotas" tabIndex={-1} aria-invalid={Boolean(errores.num_cuotas)} aria-describedby={errores.num_cuotas ? 'checkout-cuotas-error' : undefined}>
                <legend>Número de cuotas semanales</legend>
                {errores.num_cuotas && <p id="checkout-cuotas-error" className="checkout__error" role="alert">{errores.num_cuotas}</p>}
                {[2, 3].map((n) => (
                  <label key={n} className="checkout__cuota">
                    <input type="radio" name="num_cuotas" value={n} checked={Number(datos.num_cuotas) === n} onChange={cambiar} />
                    {n} cuotas de {formatearMoneda(Math.floor((total / n) * 100) / 100)}
                  </label>
                ))}
              </fieldset>
            )}
            {tienePermiso('metodos_pago.gestionar') && (
              <div className="checkout__tarjeta-rapida">
                <CreditCard size={18} aria-hidden="true" />
                <p>Guardar una tarjeta es opcional.</p>
                <Boton variante="secundario" onClick={() => setModalTarjeta(true)}>Agregar tarjeta</Boton>
              </div>
            )}
          </Tarjeta>
        )}

        {paso === 2 && (
          <Tarjeta titulo="Revisa tu pedido">
            {datos.modalidad === 'DELIVERY' && !hayCotizacionValida && (
              <Alerta tipo="error">Sin un costo de envío válido no puedes confirmar: vuelve al paso Entrega.</Alerta>
            )}
            <ul className="checkout__items">
              {resumen.items.map((i) => (
                <li key={i.producto_id}><span>{i.nombre} × {i.cantidad}</span><span>{formatearMoneda(i.subtotal)}</span></li>
              ))}
            </ul>
            <dl className="checkout__totales">
              <dt>Subtotal</dt><dd>{formatearMoneda(resumen.subtotal)}</dd>
              <dt>Envío</dt><dd>{datos.modalidad === 'DELIVERY' ? (cotizacion.datos?.costo != null ? formatearMoneda(costoEnvio) : '—') : 'Gratis'}</dd>
              <dt className="checkout__total">Total</dt><dd className="checkout__total">{formatearMoneda(total)}</dd>
            </dl>
            <dl className="checkout__revision">
              <dt>Tipo</dt><dd>{tipoPedido === 'INMEDIATO' ? 'Compra inmediata' : 'Encargo'}</dd>
              <dt>Entrega</dt><dd>{MODALIDADES[datos.modalidad]}{datos.modalidad === 'DELIVERY' ? ` · ${datos.direccion}` : ' · San Joaquín 2052, Santa Cruz de la Sierra'}</dd>
              <dt>Recibe</dt><dd>{datos.nombre_receptor} · {datos.telefono_contacto}</dd>
              <dt>Pago</dt><dd>{datos.forma_pago === 'CUOTAS' ? `${datos.num_cuotas} cuotas semanales` : 'Pago único (QR, tarjeta o efectivo)'}</dd>
              {datos.observacion && <><dt>Referencia del lugar</dt><dd>{datos.observacion}</dd></>}
            </dl>
            <p className="checkout__ayuda">{tipoPedido === 'ENCARGO' ? 'El encargo quedará a la espera de existencias suficientes.' : 'Al confirmar se comprueba la disponibilidad y se reservan las unidades.'} El cobro se gestiona después de crear el pedido.</p>
          </Tarjeta>
        )}
      </PlantillaFormulario>

      <div className="checkout__pie">
        <Link to="/carrito" className="checkout__volver">Volver al carrito</Link>
      </div>

      {modalTarjeta && <ModalMetodoPago alCerrar={() => setModalTarjeta(false)} />}
    </>
  )
}

function Opcion({ nombre, valor, actual, alCambiar, titulo, detalle, deshabilitada = false }) {
  return (
    <label className={`checkout__opcion${actual === valor ? ' checkout__opcion--activa' : ''}${deshabilitada ? ' checkout__opcion--deshabilitada' : ''}`}>
      <input type="radio" name={nombre} value={valor} checked={actual === valor} onChange={alCambiar} disabled={deshabilitada} />
      <span className="checkout__opcion-titulo">{titulo}</span>
      <span className="checkout__opcion-detalle">{detalle}</span>
    </label>
  )
}

function Cotizacion({ estado }) {
  if (estado.cargando) return <p className="checkout__cotizacion">Calculando el envío…</p>
  if (estado.error) return <Alerta tipo="error">{estado.error}</Alerta>
  if (!estado.datos) return null

  const c = estado.datos
  if (c.fuera_de_cobertura) {
    return <Alerta tipo="aviso">Esa ubicación está a {formatearNumero(c.distancia_km)} km, fuera de nuestra zona de entrega ({formatearNumero(c.cobertura_km)} km). Puedes elegir recojo.</Alerta>
  }

  const Vehiculo = c.vehiculo === 'AUTO' ? Car : Bike
  return (
    <div className="checkout__cotizacion checkout__cotizacion--lista">
      <Vehiculo size={22} aria-hidden="true" />
      <span>
        {formatearNumero(c.distancia_km)} km {c.estimado ? 'estimados' : 'por calle'} · {formatearNumero(c.duracion_min)} min · {formatearNumero(c.peso_kg)} kg · {c.vehiculo === 'AUTO' ? 'Auto' : 'Moto'}
      </span>
      <strong>{formatearMoneda(c.costo)}</strong>
    </div>
  )
}
