import { useCallback, useState } from 'react'

import { useSearchParams } from 'react-router-dom'

import { UserPlus, X } from 'lucide-react'

import { adminApi } from '../../api/adminApi'

import FormularioNuevoUsuario from '../../components/admin/FormularioNuevoUsuario'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'
import DialogoConfirmacion from '../../components/ui/DialogoConfirmacion'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { useSesion } from '../../hooks/useSesion'

import { formatearFechaHora, nombreRol } from '../../utils/formato'

import './Admin.css'

const ROLES = ['ADMINISTRADOR', 'PERSONAL_FEDER', 'PRODUCTOR', 'CLIENTE']

/** Administración de cuentas: alta, activación y roles. */
export default function UsuariosPagina() {
  const { sesion } = useSesion()
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => adminApi.usuarios(Object.fromEntries(parametros)), [clave])
  const [creando, setCreando] = useState(false)
  const [decision, setDecision] = useState(null)
  const [revocando, setRevocando] = useState(null)
  const [motivoRevocacion, setMotivoRevocacion] = useState('')
  const [mensaje, setMensaje] = useState(null)
  const [ocupado, setOcupado] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v !== '' && v != null ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const accion = async (id, llamada) => {
    setOcupado(id)
    setMensaje(null)
    try {
      const r = await llamada()
      setMensaje({ tipo: 'exito', texto: r.message })
      lista.recargar()
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally {
      setOcupado(null)
    }
  }

  const confirmarRevocacion = async (evento) => {
    evento.preventDefault()
    const motivo = motivoRevocacion.trim()
    if (!motivo) {
      setMensaje({ tipo: 'error', texto: 'Escriba el motivo de la revocación.' })
      return
    }
    await accion(revocando.usuario.id, () => adminApi.revocarRol(revocando.usuario.id, revocando.rol, motivo))
    setRevocando(null)
    setMotivoRevocacion('')
  }

  const prepararRol = (usuario, rol) => {
    if (!rol) return
    setDecision({
      titulo: 'Asignar rol', accion: 'Confirmar asignación',
      mensaje: `Se asignará el rol ${nombreRol(rol)} a ${usuario.nombre} (${usuario.correo}).`,
      alConfirmar: () => accion(usuario.id, () => adminApi.asignarRol(usuario.id, rol)),
    })
  }
  const cambiarActividad = (usuario) => {
    const etiqueta = usuario.activo ? 'Desactivar cuenta' : 'Activar cuenta'
    setDecision({
      titulo: etiqueta, accion: etiqueta, peligro: usuario.activo,
      mensaje: usuario.activo
        ? `${usuario.nombre} (${usuario.correo}) perderá el acceso a FEDER.`
        : `${usuario.nombre} (${usuario.correo}) podrá volver a iniciar sesión.`,
      alConfirmar: () => accion(usuario.id, () => adminApi.cambiarActivo(usuario.id, !usuario.activo)),
    })
  }

  const valor = (c) => parametros.get(c) ?? ''

  const filtros = (
    <>
      <label>Buscar<input name="q" defaultValue={valor('q')} maxLength={80} placeholder="Nombre, correo o carnet"
        onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Rol<select value={valor('rol')} onChange={(e) => filtrar({ rol: e.target.value })}>
        <option value="">Todos</option>
        {ROLES.map((r) => <option key={r} value={r}>{nombreRol(r)}</option>)}
      </select></label>
      <label>Estado<select value={valor('activo')} onChange={(e) => filtrar({ activo: e.target.value })}>
        <option value="">Todos</option><option value="1">Activas</option><option value="0">Inactivas</option>
      </select></label>
    </>
  )

  const columnas = [
    { clave: 'nombre', etiqueta: 'Nombre' },
    { clave: 'correo', etiqueta: 'Correo' },
    { clave: 'ci', etiqueta: 'Carnet' },
    {
      clave: 'roles', etiqueta: 'Roles vigentes',
      alRender: (u) => (
        <ul className="admin__roles">
          {u.roles.map((r) => (
            <li key={r}>
              {nombreRol(r)}
              {!(u.id === sesion.usuario.id && r === 'ADMINISTRADOR') && (
                <button type="button" onClick={() => { setRevocando({ usuario: u, rol: r }); setMotivoRevocacion(''); setMensaje(null) }} disabled={ocupado === u.id}
                  aria-label={`Quitar el rol ${nombreRol(r)} a ${u.nombre}`}><X size={14} aria-hidden="true" /></button>
              )}
            </li>
          ))}
          {u.roles.length === 0 && <li>Sin roles</li>}
        </ul>
      ),
    },
    { clave: 'estado', etiqueta: 'Estado', alRender: (u) => <Insignia tono={u.activo ? 'exito' : 'error'}>{u.activo ? 'Activa' : 'Inactiva'}</Insignia> },
    { clave: 'ultimo_acceso', etiqueta: 'Último acceso', alRender: (u) => (u.ultimo_acceso ? formatearFechaHora(u.ultimo_acceso) : '—') },
    {
      clave: 'acciones', etiqueta: 'Acciones', clase: 'admin__acciones-fila',
      alRender: (u) => (
        <>
          {u.id !== sesion.usuario.id && (
            <select aria-label={`Asignar rol a ${u.nombre}`} value="" disabled={ocupado === u.id}
              onChange={(e) => prepararRol(u, e.target.value)}>
              <option value="">Asignar rol…</option>
              {ROLES.filter((r) => !u.roles.includes(r)).map((r) => <option key={r} value={r}>{nombreRol(r)}</option>)}
            </select>
          )}
          {u.id !== sesion.usuario.id && (
            <Boton variante={u.activo ? 'secundario' : 'primario'} cargando={ocupado === u.id}
              onClick={() => cambiarActividad(u)}>
              {u.activo ? 'Desactivar' : 'Activar'}
            </Boton>
          )}
        </>
      ),
    },
  ]

  return (
    <PaginaListado
      titulo="Usuarios"
      descripcion="Administra cuentas: alta, activación y asignación de roles."
      accionPrincipal={!creando && (
        <Boton onClick={() => setCreando(true)}><UserPlus size={18} aria-hidden="true" /> Nueva cuenta</Boton>
      )}
      etiquetaFiltros="Filtrar usuarios"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} cuentas` : null}
      vacio={lista.datos?.usuarios.length === 0 ? { titulo: 'No hay cuentas con esos filtros', mensaje: 'Prueba quitando algún filtro de rol o estado.' } : null}
      paginacion={lista.datos?.usuarios.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {decision && <DialogoConfirmacion {...decision} alCerrar={() => setDecision(null)} />}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      {creando && (
        <FormularioNuevoUsuario alCancelar={() => setCreando(false)}
          alCrear={() => { setCreando(false); setMensaje({ tipo: 'exito', texto: 'Cuenta creada correctamente.' }); lista.recargar() }} />
      )}

      {revocando && (
        <form className="admin__confirmacion" onSubmit={confirmarRevocacion} noValidate>
          <h2>Quitar rol</h2>
          <p>Se quitará el rol <strong>{nombreRol(revocando.rol)}</strong> a <strong>{revocando.usuario.nombre}</strong>.</p>
          <label htmlFor="motivo-revocacion">Motivo</label>
          <textarea id="motivo-revocacion" value={motivoRevocacion}
            onChange={(evento) => setMotivoRevocacion(evento.target.value)} maxLength={300} rows={3} autoFocus required />
          <div className="admin__confirmacion-acciones">
            <Boton tipo="button" variante="fantasma" onClick={() => { setRevocando(null); setMotivoRevocacion('') }}>Cancelar</Boton>
            <Boton tipo="submit" variante="peligro" cargando={ocupado === revocando.usuario.id}>Quitar rol</Boton>
          </div>
        </form>
      )}

      <TablaDatos columnas={columnas} filas={lista.datos?.usuarios ?? []} />
    </PaginaListado>
  )
}
