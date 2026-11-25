import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { useSesion } from '../../hooks/useSesion'
import './SolicitudProductor.css'

/**
 * Solicitud para afiliarse como productor (compartida: la página
 * /mi-solicitud la usa completa y Mi cuenta / perfil el modo compacto).
 * Llama a GET/POST /productor/solicitud (perfil.ver en el backend).
 * Estados manejados: sin solicitud (CTA), PENDIENTE (seguimiento),
 * RECHAZADA (motivo + corregir) y APROBADA (confirmación, sin formulario).
 */
export default function SolicitudProductor({ estado, comunidades = [], alEnviar, alVerDetalle, compacto = false }) {
  const enLista = comunidades.some((c) => c.id === estado?.comunidad_id)
  const [valores, setValores] = useState({
    comunidad_id: enLista ? estado.comunidad_id : '', ubicacion_actividad: estado?.ubicacion ?? '',
    nueva: Boolean(estado && !enLista), nombre: estado && !enLista ? estado.comunidad : '', municipio: estado && !enLista ? estado.municipio : '', departamento: 'Santa Cruz',
  })
  const [mensaje, setMensaje] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const navegar = useNavigate()
  const { tienePermiso } = useSesion()
  useMensajeTemporal(mensaje, setMensaje)
  const cambiar = (campo) => (e) => setValores((v) => ({ ...v, [campo]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  if (compacto) {
    // Sin solicitud todavía: invitación con llamada a la acción.
    if (!estado) {
      return (
        <section className="solicitud-productor">
          <h2 className="solicitud-productor__titulo">¿Produces en tu comunidad?</h2>
          <p className="solicitud-productor__nota">Ofrece tu producción a FEDER y llega a más compradores.</p>
          <Boton onClick={alVerDetalle ?? (() => {})} aria-label="Iniciar mi solicitud de productor">
            Quiero ser productor
          </Boton>
        </section>
      )
    }
    if (estado.estado === 'PENDIENTE') {
      return (
        <section className="solicitud-productor">
          <h2 className="solicitud-productor__titulo">Solicitud de productor en revisión</h2>
          <Alerta tipo="aviso">Tu solicitud está pendiente de revisión.</Alerta>
          <p className="solicitud-productor__linea">{estado.comunidad} · {estado.municipio}</p>
          <Boton variante="secundario" onClick={alVerDetalle ?? (() => {})} aria-label="Ver el seguimiento de mi solicitud">
            Ver seguimiento completo
          </Boton>
        </section>
      )
    }
    if (estado.estado === 'RECHAZADA') {
      return (
        <section className="solicitud-productor">
          <h2 className="solicitud-productor__titulo">Solicitud de productor rechazada</h2>
          <Alerta tipo="error">Motivo: {estado.motivo_rechazo}</Alerta>
          <p className="solicitud-productor__nota">Puedes corregir los datos y volver a enviarla.</p>
          <Boton variante="secundario" onClick={alVerDetalle ?? (() => {})} aria-label="Corregir mi solicitud rechazada">
            Corregir solicitud
          </Boton>
        </section>
      )
    }
    if (estado.estado === 'APROBADA') {
      // Confirmación, sin formulario: quien ya fue aprobado no reenvía nada.
      return (
        <section className="solicitud-productor">
          <h2 className="solicitud-productor__titulo">Ya eres productor de FEDER</h2>
          <Alerta tipo="exito">Tu solicitud fue aprobada: {estado.comunidad} · {estado.municipio}.</Alerta>
          {tienePermiso('productor.ficha') && (
            <Boton variante="secundario" onClick={() => navegar('/productor/ficha')} aria-label="Completar mi ficha de productor">
              Completa tu ficha del productor
            </Boton>
          )}
        </section>
      )
    }
    return null
  }

  // Vista completa: una solicitud aprobada nunca ofrece el formulario de reenvío.
  if (estado?.estado === 'APROBADA') {
    return (
      <section className="solicitud-productor">
        <h1 className="solicitud-productor__titulo">Ya eres productor de FEDER</h1>
        <Alerta tipo="exito">Tu solicitud fue aprobada: {estado.comunidad} · {estado.municipio}.</Alerta>
        <p className="solicitud-productor__nota">Completa tu ficha del productor para que FEDER conozca tu producción.</p>
        {tienePermiso('productor.ficha') && (
          <Boton onClick={() => navegar('/productor/ficha')} aria-label="Ir a mi ficha de productor">
            Ir a mi ficha del productor
          </Boton>
        )}
      </section>
    )
  }

  if (estado?.estado === 'PENDIENTE') {
    return (
      <section className="solicitud-productor">
        <h1 className="solicitud-productor__titulo">Solicitud para ser productor</h1>
        <Alerta tipo="aviso">Tu solicitud está pendiente de revisión.</Alerta>
        <p className="solicitud-productor__linea">{estado.comunidad} · {estado.municipio}{estado.comunidad_nueva && ' (comunidad nueva: se registrará si aprueban tu solicitud)'}</p>
        <p className="solicitud-productor__nota">{estado.ubicacion}</p>
        <p className="solicitud-productor__nota">El Personal de FEDER la revisará; recibirás una notificación con la decisión.</p>
      </section>
    )
  }

  const enviar = async (evento) => {
    evento.preventDefault()
    const faltaComunidad = valores.nueva ? valores.nombre.trim().length < 3 || valores.municipio.trim().length < 3 : !valores.comunidad_id
    if (faltaComunidad || valores.ubicacion_actividad.trim().length < 10) {
      setMensaje({ tipo: 'error', texto: valores.nueva
        ? 'Escribe el nombre y el municipio de tu comunidad, y describe la ubicación con al menos 10 caracteres.'
        : 'Elige una comunidad y describe la ubicación con al menos 10 caracteres.' })
      return
    }
    setEnviando(true); setMensaje(null)
    try {
      const comunidad = valores.nueva
        ? { comunidad_nueva: { nombre: valores.nombre.trim(), municipio: valores.municipio.trim(), departamento: valores.departamento.trim() } }
        : { comunidad_id: Number(valores.comunidad_id) }
      const r = await productoresApi.solicitar({ ...comunidad, ubicacion_actividad: valores.ubicacion_actividad.trim() })
      setMensaje({ tipo: 'exito', texto: r.message }); alEnviar()
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally { setEnviando(false) }
  }

  return (
    <section className="solicitud-productor">
      <h1 className="solicitud-productor__titulo">{estado?.estado === 'RECHAZADA' ? 'Corregir solicitud de productor' : 'Quiero ser productor'}</h1>
      <p className="solicitud-productor__nota">Solicita afiliarte a FEDER para registrar tu producción y ofrecerla a la federación.</p>
      {estado?.estado === 'RECHAZADA' && <Alerta tipo="error">La solicitud anterior fue rechazada: {estado.motivo_rechazo}</Alerta>}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
      <form className="solicitud-productor__formulario" onSubmit={enviar} noValidate>
        {!valores.nueva && (
          <>
            <label htmlFor="solicitud-comunidad">Comunidad <span aria-hidden="true">*</span></label>
            <select id="solicitud-comunidad" required aria-required="true" value={valores.comunidad_id} onChange={cambiar('comunidad_id')}>
              <option value="">Elige tu comunidad</option>
              {comunidades.map((c) => <option key={c.id} value={c.id}>{c.nombre} · {c.municipio}</option>)}
            </select>
          </>
        )}
        <label className="solicitud-productor__casilla">
          <input type="checkbox" checked={valores.nueva} onChange={cambiar('nueva')} />
          Mi comunidad no está en la lista
        </label>
        {valores.nueva && (
          <fieldset className="solicitud-productor__comunidad-nueva">
            <legend>Tu comunidad</legend>
            <p className="solicitud-productor__nota">El personal de FEDER la revisará junto con tu solicitud; si la aprueba, quedará registrada para todos.</p>
            <label htmlFor="comunidad-nombre">Nombre de la comunidad <span aria-hidden="true">*</span></label>
            <input id="comunidad-nombre" required aria-required="true" maxLength={120} value={valores.nombre} onChange={cambiar('nombre')} placeholder="Ej.: Comunidad San Pedro" />
            <label htmlFor="comunidad-municipio">Municipio <span aria-hidden="true">*</span></label>
            <input id="comunidad-municipio" required aria-required="true" maxLength={80} value={valores.municipio} onChange={cambiar('municipio')} placeholder="Ej.: Cotoca" />
            <label htmlFor="comunidad-departamento">Departamento</label>
            <input id="comunidad-departamento" maxLength={40} value={valores.departamento} onChange={cambiar('departamento')} />
          </fieldset>
        )}
        <label htmlFor="solicitud-ubicacion">Ubicación de la actividad <span aria-hidden="true">*</span></label>
        <textarea id="solicitud-ubicacion" required aria-required="true" rows={3} maxLength={200} value={valores.ubicacion_actividad} onChange={cambiar('ubicacion_actividad')}
          placeholder="Ej.: parcela familiar, camino a…" />
        <Boton tipo="submit" cargando={enviando}>{estado ? 'Reenviar solicitud' : 'Enviar solicitud'}</Boton>
      </form>
    </section>
  )
}
