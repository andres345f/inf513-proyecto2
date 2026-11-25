import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import SolicitudProductor from '../../components/cliente/SolicitudProductor'
import { autenticacionApi } from '../../api/autenticacionApi'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import CampoTexto from '../../components/ui/CampoTexto'
import { useSesion } from '../../hooks/useSesion'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { formatearFechaHora, nombreRol } from '../../utils/formato'
import {
  contrasenaSegura, correo, igualA, requerido, soloLetras, telefonoBoliviano, validar as validarCampos,
} from '../../utils/validaciones'
import './PerfilPagina.css'

export default function PerfilPagina() {
  const { sesion, cambiarRol } = useSesion()
  const navegar = useNavigate()
  const [cambiando, setCambiando] = useState(null)
  const [mensaje, setMensaje] = useState(null)
  const [editando, setEditando] = useState(false)
  const [avisoDatos, setAvisoDatos] = useState(null)
  const [cambiandoContrasena, setCambiandoContrasena] = useState(false)
  const [avisoContrasena, setAvisoContrasena] = useState(null)
  const { usuario } = sesion
  const puedeSolicitar = sesion.roles.some((rol) => rol.codigo === 'CLIENTE') && !sesion.roles.some((rol) => rol.codigo === 'PRODUCTOR')
  const soloUnRol = sesion.roles.length <= 1
  const solicitud = useCargaApi(() => puedeSolicitar ? productoresApi.estadoSolicitud() : Promise.resolve({ solicitud: null }), [puedeSolicitar])
  useMensajeTemporal(mensaje, setMensaje)
  useMensajeTemporal(avisoDatos, setAvisoDatos, 4500, Boolean(avisoDatos))
  useMensajeTemporal(avisoContrasena, setAvisoContrasena, 6000, Boolean(avisoContrasena))

  const alCambiar = async (rol) => {
    setCambiando(rol.id)
    setMensaje(null)
    try {
      await cambiarRol(rol.id)
      setMensaje({ tipo: 'exito', texto: `Ahora trabajas como ${nombreRol(rol.codigo)}.` })
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setCambiando(null)
    }
  }

  return (
    <div className="perfil">
      <div className="perfil__tarjeta entrada">
        <div className="perfil__identidad">
          <span className="perfil__avatar" aria-hidden="true">
            {usuario.nombre_completo.split(' ').filter(Boolean).slice(0, 2).map((palabra) => palabra[0].toUpperCase()).join('')}
          </span>
          <div className="perfil__presentacion">
            <p className="perfil__sobretitulo">Mi perfil</p>
            <h1>{usuario.nombre_completo}</h1>
            <p>{usuario.correo}</p>
          </div>
          <span className="perfil__rol-chip">{nombreRol(sesion.rol_activo.codigo)}</span>
        </div>

        <div className={`perfil__cuerpo${soloUnRol ? ' perfil__cuerpo--sin-roles' : ''}`}>
          <section className="perfil__seccion">
            <h2 className="perfil__seccion-titulo">Datos personales</h2>
            {editando ? (
              <FormularioDatosPersonales usuario={usuario} alTerminar={(texto) => { setEditando(false); setAvisoDatos(texto) }}
                alCancelar={() => setEditando(false)} />
            ) : (
              <>
                {avisoDatos && <Alerta tipo="exito">{avisoDatos}</Alerta>}
                <dl className="perfil__datos">
                  <div className="perfil__dato perfil__dato--ancho">
                    <dt>Nombre completo</dt>
                    <dd>{usuario.nombre_completo}</dd>
                  </div>
                  <div className="perfil__dato perfil__dato--ancho">
                    <dt>Correo electrónico</dt>
                    <dd>{usuario.correo}</dd>
                  </div>
                  <div className="perfil__dato">
                    <dt>Teléfono</dt>
                    <dd>{usuario.telefono}</dd>
                  </div>
                  <div className="perfil__dato">
                    <dt>Sesión válida hasta</dt>
                    <dd>{formatearFechaHora(sesion.expira_en)}</dd>
                  </div>
                </dl>
                <div className="perfil__acciones">
                  <Boton variante="secundario" onClick={() => { setAvisoDatos(null); setEditando(true) }}>Editar mis datos</Boton>
                </div>
              </>
            )}
          </section>

          <section className="perfil__seccion">
            <h2 className="perfil__seccion-titulo">Contraseña</h2>
            {cambiandoContrasena ? (
              <FormularioContrasena alTerminar={(texto) => { setCambiandoContrasena(false); setAvisoContrasena(texto) }}
                alCancelar={() => setCambiandoContrasena(false)} />
            ) : (
              <>
                {avisoContrasena && <Alerta tipo="exito">{avisoContrasena}</Alerta>}
                <p className="perfil__nota">Cámbiala si la recibiste de FEDER o si crees que alguien más la conoce.</p>
                <div className="perfil__acciones">
                  <Boton variante="secundario" onClick={() => { setAvisoContrasena(null); setCambiandoContrasena(true) }}>Cambiar contraseña</Boton>
                </div>
              </>
            )}
          </section>

          {!soloUnRol && (
            <section className="perfil__seccion perfil__seccion--lateral">
            <h2 className="perfil__seccion-titulo">Mis roles</h2>
            <p className="perfil__nota">
              Los permisos y el menú dependen del rol con el que trabajas en este momento.
            </p>
            {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
            <ul className="perfil__roles">
              {sesion.roles.map((rol) => {
                const activo = rol.id === sesion.rol_activo.id
                return (
                  <li key={rol.id} className={`perfil__rol${activo ? ' perfil__rol--activo' : ''}`}>
                    <span>
                      <strong>{nombreRol(rol.codigo)}</strong>
                      {activo && <span className="perfil__insignia">Rol activo</span>}
                    </span>
                    {!activo && (
                      <Boton variante="secundario" cargando={cambiando === rol.id} onClick={() => alCambiar(rol)}>
                        Usar este rol
                      </Boton>
                    )}
                  </li>
                )
              })}
            </ul>
          </section>
          )}

          {puedeSolicitar && !solicitud.cargando && (
            solicitud.datos?.solicitud ? (
              <section className="perfil__seccion perfil__seccion--ancha">
                <SolicitudProductor
                  estado={solicitud.datos.solicitud}
                  compacto
                  alVerDetalle={() => navegar('/mi-solicitud')}
                />
              </section>
            ) : (
              <section className="perfil__seccion perfil__seccion--ancha">
                <h2 className="perfil__seccion-titulo">¿Quieres venderle a FEDER?</h2>
                <p className="perfil__nota">
                  Envía una solicitud para afiliarte como productor: podrás registrar tu
                  producción y ofrecer tus lotes a la federación.
                </p>
                <div className="perfil__acciones">
                  <Boton variante="secundario" onClick={() => navegar('/mi-solicitud')}>
                    Quiero ser productor
                  </Boton>
                </div>
              </section>
            )
          )}
        </div>
      </div>
    </div>
  )
}

/** Edición de nombres, apellidos, correo y teléfono. Si cambia el correo se pide la contraseña actual. */
function FormularioDatosPersonales({ usuario, alTerminar, alCancelar }) {
  const { actualizarPerfil } = useSesion()
  const [valores, setValores] = useState({
    nombres: usuario.nombres, apellidos: usuario.apellidos, correo: usuario.correo, telefono: String(usuario.telefono), password_actual: '',
  })
  const [errores, setErrores] = useState({})
  const [mensaje, setMensaje] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const cambiaCorreo = valores.correo.trim().toLowerCase() !== usuario.correo.toLowerCase()
  const cambiar = (e) => setValores((v) => ({ ...v, [e.target.name]: e.target.value }))

  const validar = () => {
    const nuevos = validarCampos(valores, {
      nombres: [requerido('Ingresa tus nombres.'), soloLetras('Los nombres')],
      apellidos: [requerido('Ingresa tus apellidos.'), soloLetras('Los apellidos')],
      correo: [requerido('Ingresa tu correo electrónico.'), correo()],
      telefono: [requerido('Ingresa tu teléfono.'), telefonoBoliviano()],
    })
    if (cambiaCorreo && !valores.password_actual) nuevos.password_actual = 'Para cambiar el correo escribe tu contraseña actual.'
    return nuevos
  }

  const guardar = async (e) => {
    e.preventDefault()
    const nuevos = validar()
    setErrores(nuevos)
    if (Object.keys(nuevos).length) return
    setGuardando(true)
    setMensaje(null)
    try {
      const texto = await actualizarPerfil({
        nombres: valores.nombres.trim(), apellidos: valores.apellidos.trim(), correo: valores.correo.trim(),
        telefono: valores.telefono.trim(), password_actual: cambiaCorreo ? valores.password_actual : null,
      })
      alTerminar(texto)
    } catch (falla) {
      setErrores(Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c, m[0]])))
      setMensaje(falla.mensaje)
    } finally {
      setGuardando(false)
    }
  }

  return (
    <form className="perfil__formulario" onSubmit={guardar} noValidate aria-label="Editar datos personales">
      {mensaje && <Alerta tipo="error">{mensaje}</Alerta>}
      <CampoTexto etiqueta="Nombres" nombre="nombres" autoComplete="given-name" obligatorio maxLength={80} value={valores.nombres} onChange={cambiar} error={errores.nombres} />
      <CampoTexto etiqueta="Apellidos" nombre="apellidos" autoComplete="family-name" obligatorio maxLength={80} value={valores.apellidos} onChange={cambiar} error={errores.apellidos} />
      <CampoTexto etiqueta="Correo electrónico" nombre="correo" tipo="email" autoComplete="email" obligatorio maxLength={120} value={valores.correo} onChange={cambiar}
        error={errores.correo} ayuda="Es el correo con el que inicias sesión." />
      <CampoTexto etiqueta="Teléfono" nombre="telefono" tipo="tel" inputMode="numeric" autoComplete="tel" obligatorio maxLength={8} value={valores.telefono} onChange={cambiar} error={errores.telefono} />
      {cambiaCorreo && (
        <CampoTexto etiqueta="Contraseña actual" nombre="password_actual" tipo="password" obligatorio autoComplete="current-password"
          value={valores.password_actual} onChange={cambiar} error={errores.password_actual} ayuda="La pedimos porque vas a cambiar tu correo." />
      )}
      <p className="perfil__nota">El carnet de identidad no se puede cambiar desde aquí.</p>
      <div className="perfil__acciones">
        <Boton variante="fantasma" onClick={alCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tipo="submit" cargando={guardando}>Guardar cambios</Boton>
      </div>
    </form>
  )
}

const VACIO_CONTRASENA = { contrasena_actual: '', contrasena_nueva: '', contrasena_nueva_confirmation: '' }

/** Cambio de contraseña (PUT /api/auth/contrasena). El servidor cierra las demás sesiones de la cuenta. */
function FormularioContrasena({ alTerminar, alCancelar }) {
  const [valores, setValores] = useState(VACIO_CONTRASENA)
  const [errores, setErrores] = useState({})
  const [mensaje, setMensaje] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const cambiar = (e) => {
    const { name, value } = e.target
    setValores((v) => ({ ...v, [name]: value }))
    setErrores((actual) => (actual[name] ? { ...actual, [name]: null } : actual))
  }

  const guardar = async (e) => {
    e.preventDefault()
    const nuevos = validarCampos(valores, {
      contrasena_actual: [requerido('Escribe tu contraseña actual.')],
      contrasena_nueva: [
        requerido('Escribe la nueva contraseña.'), contrasenaSegura(),
        (valor, todos) => (valor && valor === todos.contrasena_actual ? 'La nueva contraseña debe ser distinta de la actual.' : null),
      ],
      contrasena_nueva_confirmation: [requerido('Repite la nueva contraseña.'), igualA('contrasena_nueva', 'Las contraseñas nuevas no coinciden.')],
    })
    setErrores(nuevos)
    if (Object.keys(nuevos).length) return
    setGuardando(true)
    setMensaje(null)
    try {
      const respuesta = await autenticacionApi.cambiarContrasena(valores)
      setValores(VACIO_CONTRASENA)
      alTerminar(respuesta?.message || 'Tu contraseña se cambió. Cerramos tus otras sesiones abiertas.')
    } catch (falla) {
      setErrores(Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c, m[0]])))
      setMensaje(falla.mensaje)
    } finally {
      setGuardando(false)
    }
  }

  return (
    <form className="perfil__formulario" onSubmit={guardar} noValidate aria-label="Cambiar contraseña">
      {mensaje && <Alerta tipo="error">{mensaje}</Alerta>}
      <CampoTexto etiqueta="Contraseña actual" nombre="contrasena_actual" tipo="password" obligatorio autoComplete="current-password"
        value={valores.contrasena_actual} onChange={cambiar} error={errores.contrasena_actual} />
      <CampoTexto etiqueta="Nueva contraseña" nombre="contrasena_nueva" tipo="password" obligatorio autoComplete="new-password" maxLength={72}
        value={valores.contrasena_nueva} onChange={cambiar} error={errores.contrasena_nueva} ayuda="Al menos 8 caracteres, con letras y números." />
      <CampoTexto etiqueta="Repite la nueva contraseña" nombre="contrasena_nueva_confirmation" tipo="password" obligatorio autoComplete="new-password" maxLength={72}
        value={valores.contrasena_nueva_confirmation} onChange={cambiar} error={errores.contrasena_nueva_confirmation} />
      <Alerta tipo="info">Al cambiarla se cerrarán tus sesiones abiertas en otros navegadores o equipos; esta sesión sigue activa.</Alerta>
      <div className="perfil__acciones">
        <Boton variante="fantasma" onClick={alCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tipo="submit" cargando={guardando}>Cambiar contraseña</Boton>
      </div>
    </form>
  )
}