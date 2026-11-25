import { useState } from 'react'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import MensajeCampo from '../../components/ui/MensajeCampo'
import Tarjeta from '../../components/ui/Tarjeta'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { useSesion } from '../../hooks/useSesion'
import { formatearFecha } from '../../utils/formato'
import {
  atributosError, enfocarPrimerError, erroresDeApi, longitudMaxima, longitudMinima, requerido, validar,
} from '../../utils/validaciones'
import './Productor.css'

const REGLAS_FICHA = {
  ubicacion_actividad: [
    requerido('Describe dónde realizas tu actividad productiva.'),
    longitudMinima(10, 'Describe el lugar de producción con al menos 10 caracteres.'),
    longitudMaxima(200, 'El lugar de producción'),
  ],
}
const ESTADOS = { PENDIENTE: ['Solicitud en revisión', 'aviso'], APROBADA: ['Productor afiliado', 'exito'], RECHAZADA: ['Solicitud rechazada', 'error'] }

export default function MiFichaPagina() {
  const { sesion } = useSesion()
  const ficha = useCargaApi(() => productoresApi.miFicha(), [])
  const comunidades = useCargaApi(() => productoresApi.comunidadesDisponibles(), [])
  const [editando, setEditando] = useState(false)
  const [formulario, setFormulario] = useState({ ubicacion_actividad: '', comunidad_id: '' })
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [errores, setErrores] = useState({})
  const [guardando, setGuardando] = useState(false)

  useMensajeTemporal(mensaje, setMensaje, 4500, Boolean(mensaje))

  const guardar = async (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    const erroresCliente = validar(formulario, REGLAS_FICHA)
    setErrores(erroresCliente)
    if (Object.keys(erroresCliente).length > 0) {
      setError('Revisa los campos marcados.')
      enfocarPrimerError(formularioHtml)
      return
    }
    setGuardando(true); setError('')
    try {
      const respuesta = await productoresApi.actualizarFicha({ ...formulario, comunidad_id: formulario.comunidad_id || null })
      setMensaje(respuesta.message); setEditando(false); ficha.recargar()
    } catch (falla) {
      setError(falla.mensaje)
      setErrores(erroresDeApi(falla))
      enfocarPrimerError(formularioHtml)
    } finally { setGuardando(false) }
  }
  const cambiar = (campo, valor) => {
    setFormulario((v) => ({ ...v, [campo]: valor }))
    setErrores((actual) => (actual[campo] ? { ...actual, [campo]: null } : actual))
  }

  if (ficha.cargando) return <Cargando texto="Cargando tu ficha…" />
  if (ficha.error) return <Alerta tipo="error">{ficha.error}</Alerta>
  const f = ficha.datos
  const [texto, tono] = ESTADOS[f.estado] || [f.estado, 'info']

  return <div className="productor">
    <EncabezadoPagina
      titulo="Mi ficha de productor"
      descripcion="Datos productivos y comunidad de afiliación."
      accion={!editando && f.estado === 'APROBADA' && f.activo && (
        <Boton variante="secundario" onClick={() => { setFormulario({ ubicacion_actividad: ficha.datos.ubicacion || '', comunidad_id: String(ficha.datos.comunidad_id || '') }); setErrores({}); setError(''); setEditando(true) }}>Editar ficha</Boton>
      )}
    />
    {mensaje && <Alerta tipo="exito">{mensaje}</Alerta>}{error && <Alerta tipo="error">{error}</Alerta>}
    <Tarjeta><div className="productor__cabecera"><div><h2>{sesion.usuario.nombre_completo}</h2><p>{f.codigo ? `Código ${f.codigo}` : 'Sin código asignado'}</p></div><Insignia tono={tono}>{texto}</Insignia></div>
      {editando ? <form className="productor__formulario" onSubmit={guardar} noValidate>
        <label htmlFor="ficha-comunidad">Comunidad agrícola
          <select id="ficha-comunidad" value={formulario.comunidad_id} onChange={(e) => cambiar('comunidad_id', e.target.value)} {...atributosError(errores, 'comunidad_id', 'ficha-comunidad-error')}>
            <option value="">Mantener comunidad actual</option>
            {comunidades.datos?.comunidades?.map((c) => <option key={c.id} value={c.id}>{c.nombre} · {c.municipio}</option>)}
          </select>
          <MensajeCampo id="ficha-comunidad-error" error={errores.comunidad_id} />
        </label>
        <label htmlFor="ficha-ubicacion">Lugar de producción
          <textarea id="ficha-ubicacion" aria-required="true" maxLength={200} rows={3} value={formulario.ubicacion_actividad}
            onChange={(e) => cambiar('ubicacion_actividad', e.target.value)} {...atributosError(errores, 'ubicacion_actividad', 'ficha-ubicacion-error')} />
          <MensajeCampo id="ficha-ubicacion-error" error={errores.ubicacion_actividad} />
        </label>
        <p className="productor__ayuda">El cambio de comunidad necesita aprobación de FEDER; tu comunidad actual seguirá vigente mientras se revisa.</p>
        <div className="productor__acciones"><Boton tipo="submit" cargando={guardando}>Guardar cambios</Boton><Boton variante="fantasma" onClick={() => { setEditando(false); setErrores({}); setError('') }}>Cancelar</Boton></div>
      </form> : <dl className="productor__datos"><dt>Comunidad</dt><dd>{f.comunidad}</dd><dt>Ubicación</dt><dd>{f.municipio}, {f.departamento}</dd><dt>Lugar de producción</dt><dd>{f.ubicacion}</dd><dt>Solicitud enviada</dt><dd>{formatearFecha(f.fecha_solicitud)}</dd>{f.fecha_afiliacion && <><dt>Afiliado desde</dt><dd>{formatearFecha(f.fecha_afiliacion)}</dd></>}{f.motivo_rechazo && <><dt>Motivo del rechazo</dt><dd>{f.motivo_rechazo}</dd></>}</dl>}
    </Tarjeta>
  </div>
}
